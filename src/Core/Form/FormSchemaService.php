<?php
/**
 * 表单元数据只读服务实现
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use Psr\Container\ContainerInterface;
use SlimCMS\Core\Form\DTO\FormDTO;
use SlimCMS\Core\Form\DTO\FieldSchemaCollection;
use SlimCMS\Core\Redis;
use SlimCMS\Core\Table;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\Str;

/**
 * 表单元数据只读服务
 *
 * 职责：
 *  - 读取 forms / forms_fields 表，构造不可变 DTO
 *  - 进程内 static + Redis 双层缓存
 *  - 提供 analysisRules / tableDataRules 动态规则解析
 *
 * 不依赖任何业务服务，仅依赖 Redis + Table（数据访问）。
 */
final class FormSchemaService implements FormSchemaServiceInterface
{
    /** @var array<int,FormDTO> 进程内缓存 */
    private static array $formCache = [];

    /** @var array<string,FieldSchemaCollection> 进程内字段缓存 */
    private static array $fieldsCache = [];

    /** @var array<string,array> 动态规则缓存 */
    private static array $dynamicRulesCache = [];

    public function __construct(
        private ContainerInterface $container,
        private Redis $redis,
    ) {}

    public function getForm(int $fid): FormDTO
    {
        if (empty($fid)) {
            throw new TextException(21002);
        }
        if (isset(self::$formCache[$fid])) {
            return self::$formCache[$fid];
        }

        $row = $this->table('forms')->withWhere(['id' => $fid])->fetch();
        if (empty($row)) {
            throw new TextException(22006);
        }

        return self::$formCache[$fid] = FormDTO::fromRow($row);
    }

    public function getFields(int $fid, array $criteria = [], int $limit = 200): FieldSchemaCollection
    {
        $cacheKey = $fid . ':' . serialize($criteria) . ':' . $limit;
        if (isset(self::$fieldsCache[$cacheKey])) {
            return self::$fieldsCache[$cacheKey];
        }

        $where = array_merge(['formid' => $fid, 'available' => 1], $criteria);
        $rows = $this->table('forms_fields')
            ->withWhere($where)
            ->withLimit($limit)
            ->withOrderby('displayorder desc,id')
            ->fetchList('*');

        $collection = new FieldSchemaCollection($rows);
        return self::$fieldsCache[$cacheKey] = $collection;
    }

    public function allValidFields(int $fid): FieldSchemaCollection
    {
        return $this->getFields($fid, []);
    }

    public function resolveDynamicRules(array $rules): array
    {
        if (empty($rules)) {
            return [];
        }
        // 单条规则才走动态解析
        if (count($rules) !== 1) {
            return $rules;
        }
        $cacheKey = 'dynRules:' . md5(serialize($rules));
        if (isset(self::$dynamicRulesCache[$cacheKey])) {
            return self::$dynamicRulesCache[$cacheKey];
        }

        $result = $this->analysisRules($rules);
        if (empty($result) || empty($result['name']) || empty($result['value'])) {
            return self::$dynamicRulesCache[$cacheKey] = $rules;
        }

        $field = str_replace('_', '', $result['value'] . ',' . $result['name']);
        $page = $pageSize = 0;
        if (!empty($result['limit'])) {
            [$page, $pageSize] = explode(',', $result['limit']);
        }

        $list = $this->table($result['table'])
            ->withWhere($result['condition'])
            ->withLimit((int)$pageSize, (int)$page)
            ->withOrderby($result['order'], $result['way'])
            ->fetchList($field);

        // 反查 forms_fields 用于 exchangeFieldValue 字段值反解
        $formRow = $this->table('forms')->withWhere(['table' => $result['table']])->fetch('id');
        $fieldSchemas = [];
        if (!empty($formRow['id'])) {
            $fields = $this->getFields((int)$formRow['id']);
            $fieldSchemas = $fields->toRawArray();
        }

        $val = [];
        foreach ($list as $row) {
            if (!empty($fieldSchemas)) {
                $row = $this->exchangeFieldValue($fieldSchemas, $row);
            }
            if (strpos($result['name'], ',')) {
                $arr = [];
                foreach (explode(',', $result['name']) as $f) {
                    $arr[] = $row[$f];
                }
                $val[$row[$result['value']]] = implode('/', $arr);
            } else {
                $val[$row[$result['value']]] = $row[$result['name']];
            }
        }
        return self::$dynamicRulesCache[$cacheKey] = $val;
    }

    public function analysisRules(array $rules): array
    {
        if (empty($rules) || count($rules) != 1) {
            return [];
        }
        $table = array_key_first($rules);
        $setting = $this->container->get('settings');
        $tablepre = $setting['db']['tablepre'];
        $db = $this->table()->db();
        $tableName = $tablepre . str_replace($tablepre, '', $table);

        // [SQL安全改造] SHOW TABLES LIKE 参数化
        if (!$db->fetch('SHOW TABLES LIKE ?', [$tableName])) {
            return [];
        }

        $result = Str::htmlspecialchars($rules[$table], 'de');
        // 筛选条件支持外部传参 $xxx$
        preg_match_all('|\$#(\w+)\$#|isU', $result, $mat);
        if (!empty($mat[1])) {
            $request = $this->container->get(\Psr\Http\Message\ServerRequestInterface::class);
            foreach ($mat[1] as $v) {
                $params = $request->getQueryParams() + (array)$request->getParsedBody();
                $val = $params[$v] ?? '';
                $result = str_replace('$#' . $v . '$#', $val, $result);
            }
        }
        $result = json_decode($result, true);

        $order = $way = '';
        $orderby = $result['orderby'] ?? '';
        if ($orderby && strpos($orderby, ',')) {
            [$order, $way] = explode(',', $orderby);
        } else {
            $order = $orderby;
        }

        return [
            'table' => $table,
            'value' => $result['value'] ?? '',
            'name' => $result['name'] ?? '',
            'condition' => $result['condition'] ?? '',
            'limit' => $result['limit'] ?? '',
            'order' => (string)$order,
            'way' => (string)$way,
        ];
    }

    /**
     * 数据库查询数据字段值反解（从原 Forms::exchangeFieldValue 抽出，纯函数）
     */
    public function exchangeFieldValue(array $fieldSchemas, array $row): array
    {
        return \SlimCMS\Core\Form\FormValueTransformer::exchange($fieldSchemas, $row, $this->container);
    }

    /**
     * 获取 Table 实例（私有，仅供本类数据访问）
     *
     * 注：FormSchemaService 在 DI 中注册为单例，Table 通过容器 make 每次新实例，
     * 并通过 setRequest 注入当前请求（与原 Forms::t() 行为等价）。
     */
    private function table(string $name = ''): Table
    {
        // 解析 app\Table\*Table 子类，保证子类 setTableName 分表覆写生效
        $classname = $name ? '\app\Table\\' . ucfirst($name) . 'Table' : '';
        if (!$classname || !class_exists($classname)) {
            $classname = Table::class;
        }
        $app = $this->container->get(\Slim\App::class);
        $instance = $this->container->make($classname, ['app' => $app]);
        // 注入当前请求，保证 Table 内部 fetch 缓存键与请求参数绑定
        try {
            $request = $this->container->get(\Psr\Http\Message\ServerRequestInterface::class);
            $instance->setRequest($request);
        } catch (\Throwable $e) {
            // 启动期无请求上下文时忽略
        }
        return $name ? $instance->setTableName($name) : $instance;
    }
}
