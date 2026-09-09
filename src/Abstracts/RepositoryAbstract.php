<?php
/**
 * 数据库内容相关读写操作
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use Respect\Validation\Exceptions\ValidationException;
use Slim\App;
use SlimCMS\Core\Form\FormQueryServiceInterface;
use SlimCMS\Core\Form\FormWriteServiceInterface;
use SlimCMS\Core\Redis;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\FileCache;
use SlimCMS\Interfaces\OutputInterface;

abstract class RepositoryAbstract extends BaseAbstract
{
    use \SlimCMS\Traits\Table;

    protected $where = [];
    protected $by = '';
    protected $order = '';
    protected $orderForce = true;
    protected $joins = [];//联表
    protected $joinFields = '';//联表查询字段
    protected $indexField = '';//索引字段
    protected $extendFormName = '';//分表表名
    protected $groupBy = '';//分组排序
    protected $respExtraRowFields = '';//行额外数据
    protected $respExtraFields = '';//列表额外数据
    protected $auth;
    protected $query = [];//查询参数
    private $tableName = '';
    /** @var string 显式指定表名（用于通用仓库兜底，为空时按类名推断） */
    protected string $forceTableName = '';
    private $formId = 0;
    protected $setting;//站点初始化参数
    protected array $config;//后台配置参数
    protected OutputInterface $output;
    protected FormWriteServiceInterface $formWrite;
    protected FormQueryServiceInterface $formQuery;
    protected Redis $redis;
    protected int $page = 1;
    protected int $pageSize = 0;
    /**
     * 实体包装类：子类设置后，fetch/fetchList/list 默认把数据行包装为该 Entity 实例
     * 未设置时返回 stdClass，业务侧 ->field 访问方式保持兼容
     * @var class-string<EntityAbstract>|null
     */
    protected ?string $entityClass = null;

    public function __construct(App $app, FormWriteServiceInterface $formWrite, FormQueryServiceInterface $formQuery, Redis $redis)
    {
        parent::__construct($app);
        $this->setting = $this->container->get('settings');
        $this->config = $this->container->get('cfg');
        $this->output = $this->container->get(OutputInterface::class)($app);
        $this->formWrite = $formWrite;
        $this->formQuery = $formQuery;
        $this->redis = $redis;
        $this->initialize();
    }

    protected function initialize()
    {
        $this->tableName = $this->forceTableName !== ''
            ? $this->forceTableName
            : preg_replace('/repository$/', '', strtolower(substr(strrchr(get_called_class(), '\\'), 1)));
        $list = $this->tableMap();
        $this->formId = aval($list, $this->tableName);
        if (empty($this->formId)) {
            throw new TextException(21039);
        }
    }

    /**
     * 获取表名映射
     * @return array|mixed|null
     */
    public function tableMap(bool $force = false)
    {
        $cacheKey = __FUNCTION__;
        $list = $this->getCache($cacheKey);
        if (empty($list) || $force === true) {
            $data = $this->t('forms')->fetchList('id,table');
            $list = array_column($data, 'id', 'table');
            $this->setCache($cacheKey, $list);
        }
        return $list;
    }

    /**
     * 获取缓存
     * @param string $key
     * @return mixed|null
     */
    protected function getCache(string $key)
    {
        if ($this->redis->isAvailable()) {
            return $this->redis->get($key);
        }
        return FileCache::get($key);
    }

    /**
     * 设置缓存
     * @param string $key
     * @param $value
     * @param int $ttl
     * @return bool|null
     */
    protected function setCache(string $key, $value, int $ttl = 300)
    {
        if ($this->redis->isAvailable()) {
            return $this->redis->set($key, $value, $ttl);
        }
        return FileCache::set($key, $value, $ttl);
    }

    /**
     * 数据整理
     * @param array $param 数据
     * @param array $valiIgnore 需要忽略的校验字段
     * @return array
     * @throws TextException
     */
    protected function getData(array $param, array $valiIgnore = []): array
    {
        $data = [];
        $list = $this->t('forms_fields')
            ->withWhere(['formid' => $this->formId, 'available' => 1])
            ->fetchList('identifier,datatype,rules');
        foreach ($list as $v) {
            $identifier = $v['identifier'];
            if (empty($valiIgnore) || aval($valiIgnore, $identifier) !== true) {
                //有效性校验
                $class = '\app\Model\vali\\' . ucfirst($this->tableName) . 'Vali';
                if (!empty($class) && method_exists($class, $identifier) && ($obj = $this->i($class)) && is_callable([$obj, $identifier])) {
                    $callback = $obj->$identifier();
                    try {
                        $callback->assert($param[$identifier]);
                    } catch (ValidationException $e) {
                        $messages = $e->getMessages();
                        foreach ($messages as $message) {
                            throw new TextException(21000, $message);
                        }
                    }
                }
            }
            switch ($v['datatype']) {
                case 'month':
                case 'date':
                case 'datetime':
                case 'int':
                case 'stepselect':
                    isset($param[$v['identifier']]) && $data[$v['identifier']] = (int)$param[$v['identifier']];
                    break;
                case 'float':
                case 'price':
                    isset($param[$v['identifier']]) && $data[$v['identifier']] = (float)$param[$v['identifier']];
                    break;
                case 'tel':
                    isset($param[$v['identifier']]) && $data[$v['identifier']] = preg_replace('/[^\d\-]/i', '', (string)$param[$v['identifier']]);
                    break;
                default:
                    isset($param[$v['identifier']]) && $data[$v['identifier']] = (string)$param[$v['identifier']];
                    break;
            }
        }
        return $data;
    }

    /**
     * 添加
     * @param array $param
     * @return OutputInterface
     */
    public function add(array $param, array $valiIgnore = []): OutputInterface
    {
        $data = $this->getData($param, $valiIgnore);
        if (empty($data)) {
            return $this->output->withCode(21020);
        }
        return $this->formWrite->save($this->formId, [], $data);
    }

    /**
     * 修改
     * @param int $id
     * @param array $param
     * @return OutputInterface
     */
    public function edit(int $id, array $param, array $options = [], array $valiIgnore = []): OutputInterface
    {
        if (empty($id) || empty($param)) {
            return $this->output->withCode(21003);
        }
        $data = $this->getData($param, $valiIgnore);
        if (empty($data)) {
            return $this->output->withCode(21020);
        }
        $res = $this->formQuery->view($this->formId, $id);
        if ($res->getCode() != 200) {
            return $res;
        }
        $val = $res->getData()['row'];
        $res = $this->editPreHandle($val, $param, $options);
        if ($res->getCode() != 200) {
            return $res;
        }
        return $this->formWrite->save($this->formId, $val, $data);
    }

    /**
     * 编辑前处理
     * @param $val
     * @param $param
     * @param $options
     * @return OutputInterface
     */
    protected function editPreHandle($val, $param, $options): OutputInterface
    {
        return $this->output->withCode(200);
    }

    /**
     * 删除
     * @param int $id
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function delete(int $id): OutputInterface
    {
        if (empty($id)) {
            return $this->output->withCode(21003);
        }
        return $this->formWrite->delete($this->formId, [$id]);
    }

    /**
     * 详细
     * @param int $id 信息ID
     * @param string $fields 读取字段，多个字段用,隔开
     * @param array $param 其它自定义参数
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function detail(int $id, string $fields, array $param = []): OutputInterface
    {
        if (empty($id) || empty($fields)) {
            return $this->output->withCode(21002);
        }
        $res = $this->formQuery->view($this->formId, $id, $fields);
        if ($res->getCode() != 200) {
            return $res;
        }
        $data = (array)$res->getData()['row'];
        if (!empty($this->respExtraRowFields)) {
            $data = [$data];
            $this->listRowHandle([$data]);
            $data = $data[0];
        }
        return $this->output->withCode(200)->withData($data);
    }

    /**
     * 生成筛选条件
     * @param array $param
     * @param bool $append true：追加条件
     * @param array $valiIgnore 忽略有效性校验设置
     * @return $this
     */
    public function withWhere(array $param, bool $append = true, array $valiIgnore = []): self
    {
        $clone = clone $this;
        $class = '\app\Model\req\\' . ucfirst($this->tableName) . 'Req';
        if (!empty($class) && class_exists($class)) {
            $req = $this->i($class)->getReq();
            if ($append === false) {
                $clone->where = [];
            }
            foreach ($req->getWhere($param, $valiIgnore) as $k => $v) {
                $clone->where[$this->transFields($k)] = $v;
            }
            $clone->joins = $req->getJoins();
        } else {
            // 无 Req 类的表（如未生成仓库类的动态表单表）按原始键值构建条件，仅接受字符串键防注入
            if ($append === false) {
                $clone->where = [];
            }
            foreach ($param as $k => $v) {
                if (is_string($k) && $k !== '') {
                    $clone->where[$this->transFields($k)] = $v;
                }
            }
        }
        $clone->query = $param;
        return $clone;
    }

    public function getWhere()
    {
        return $this->where;
    }

    public function getQuery()
    {
        return $this->query;
    }

    /**
     * 排序
     * @param string $field
     * @param string $direction
     * @return $this
     */
    public function withOrderBy(string $field, string $direction = 'DESC', bool $orderForce = true): self
    {
        $clone = clone $this;
        $clone->order = $this->transFields($field);
        $clone->by = $direction;
        $clone->orderForce = $orderForce;
        return $clone;
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function getBy()
    {
        return $this->by;
    }

    /**
     * 联表查询
     * @param array $joins
     * @return $this
     */
    public function withJoins(array $joins, string $fields = ''): self
    {
        $clone = clone $this;
        $clone->joins = $joins;
        $clone->joinFields = $fields;
        return $clone;
    }

    public function getJoins()
    {
        return $this->joins;
    }

    public function getJoinFields()
    {
        return $this->joinFields;
    }

    /**
     * 索引字段
     * @param string $field
     * @return $this
     */
    public function withIndexField(string $field): self
    {
        $clone = clone $this;
        $clone->indexField = $field;
        return $clone;
    }

    public function getIndexField()
    {
        return $this->indexField;
    }

    /**
     * 分组排序
     * @param string $field
     * @return $this
     */
    public function withGroupBy(string $field): self
    {
        $clone = clone $this;
        $clone->groupBy = $field;
        return $clone;
    }

    public function getGroupBy()
    {
        return $this->groupBy;
    }

    /**
     * 分表表名
     * @param string $name
     * @return $this
     */
    public function withExtendFormName(string $name): self
    {
        $clone = clone $this;
        $clone->extendFormName = $name;
        return $clone;
    }

    public function getExtendFormName()
    {
        return $this->extendFormName;
    }

    /**
     * 其它返回值
     * @param string $fields
     * @return $this
     */
    public function withRespExtraRowFields(string $fields): self
    {
        $clone = clone $this;
        $clone->respExtraRowFields = $fields;
        return $clone;
    }

    public function getRespExtraRowFields()
    {
        return $this->respExtraRowFields;
    }

    public function withrespExtraFields(string $fields): self
    {
        $clone = clone $this;
        $clone->respExtraFields = $fields;
        return $clone;
    }

    public function getRespExtraFields()
    {
        return $this->respExtraFields;
    }

    public function withLimit(int $pageSize = 30, int $page = 1): self
    {
        if ($page < 1 || $pageSize < 1) {
            return $this;
        }
        $clone = clone $this;
        $start = ($page - 1) * $pageSize;
        $clone->page = $page;
        $clone->pageSize = $pageSize;
        return $clone;
    }

    public function withAuth(array $auth): self
    {
        $clone = clone $this;
        $clone->auth = $auth;
        return $clone;
    }

    public function getAuth()
    {
        return $this->auth;
    }

    /**
     * 分页列表：list 键为 Entity 或 stdClass 数组（取决于 $entityClass）
     */
    public function list(string $fields = 'id,createtime'): array
    {
        $val = $this->listRaw($fields);
        $val['list'] = $this->wrapEntityList($val['list']);
        return $val;
    }

    /**
     * 分页列表原始数据（list 键为原始数组）
     * 子类如需定制查询逻辑请重写此方法而非 list
     */
    public function listRaw(string $fields = 'id,createtime'): array
    {
        $params = [
            'fid' => $this->formId,
            'page' => $this->page,
            'pagesize' => $this->pageSize,
            'fields' => $this->transFields($fields),
            'order' => $this->order,
            'by' => $this->by,
            'noinput' => true,
            'orderForce' => $this->orderForce,
            'joins' => $this->joins,
            'joinFields' => $this->joinFields,
            'indexField' => $this->indexField,
            'extendFormName' => $this->extendFormName,
            'groupby' => $this->groupBy,
        ];
        $params['where'] = $this->where;
        $res = $this->formQuery->list($params);
        if ($res->getCode() != 200) {
            throw new TextException($res->getCode(), $res->getMsg());
        }
        $data = $res->getData();
        if (!empty($this->respExtraRowFields)) {
            $this->listRowHandle($data['list']);
        }
        $val = [
            'list' => aval($data, 'list'),
            'count' => aval($data, 'count'),
            'maxpages' => aval($data, 'maxpages', 0),
            'page' => $this->page,
            'pagesize' => $this->pageSize
        ];
        if (!empty($this->respExtraFields)) {
            $this->listHandle($data);
            $fields = explode(',', $this->respExtraFields);
            foreach ($fields as $v) {
                isset($data[$v]) && $val[$v] = $data[$v];
            }
        }
        return $val;
    }

    /**
     * 列表数据处理
     * @param array $data
     * @return array
     */
    protected function listHandle(&$data)
    {
        $class = '\app\Model\resp\\' . ucfirst($this->tableName) . 'Resp';
        if (!empty($class) && class_exists($class)) {
            return $this->i($class)->getRespExtraData($data, $this);
        }
        return [];
    }

    /**
     * 列表行数据处理
     * @param $data
     * @return void
     */
    protected function listRowHandle(&$data)
    {
        $class = '\app\Model\resp\\' . ucfirst($this->tableName) . 'Resp';
        if (!empty($class) && class_exists($class)) {
            $this->i($class)->getRespExtraRowData($data, $this);
        }
    }

    public function batchDelete(): int
    {
        if (empty($this->where)) {
            throw new TextException(21010);
        }
        return $this->t($this->tableName)->withWhere($this->where)->withJoin($this->joins)->delete();
    }

    /**
     * 批量修改
     * @param array $value
     * @return int
     * @throws TextException
     */
    public function batchUpdate(array $value): int
    {
        if (empty($this->where) || empty($value)) {
            throw new TextException(21010);
        }
        return $this->t($this->tableName)->withWhere($this->where)->withJoin($this->joins)->update($value);
    }

    /**
     * 指定ID修改
     * @param int $id
     * @param array $value
     * @return int
     * @throws TextException
     */
    public function update(int $id, array $value): int
    {
        if (empty($id) || empty($value)) {
            throw new TextException(21010);
        }
        return $this->t($this->tableName)->withWhere(['id' => $id])->update($value);
    }

    /**
     * 数量统计
     * @param string $fields
     * @param int $cacheTime
     * @return int
     * @throws TextException
     */
    public function count(string $fields = 'id', int $cacheTime = 0): int
    {
        return $this->t($this->tableName)->withWhere($this->where)->withJoin($this->joins)->count($this->transFields($fields), $cacheTime);
    }

    /**
     * 累加统计
     * @param string $field
     * @return float
     * @throws TextException
     */
    public function sum(string $field): float
    {
        if (empty($field)) {
            throw new TextException(21010);
        }
        return (float)$this->t($this->tableName)
            ->withWhere($this->where)
            ->withJoin($this->joins)
            ->sum($this->transFields($field));
    }

    public function fetchColumn(string $field, string $func)
    {
        if (empty($field) || empty($func)) {
            throw new TextException(21010);
        }
        return $this->t($this->tableName)
            ->withWhere($this->where)
            ->withJoin($this->joins)
            ->fetchColumn($this->transFields($field), $func);
    }


    /**
     * 单行查询：返回 Entity 或 stdClass（取决于 $entityClass），统一支持 ->field 访问
     */
    public function fetch(string $field, int $cacheTime = 0): ?object
    {
        $data = $this->fetchRaw($field, $cacheTime);
        return $data ? $this->wrapEntity($data) : null;
    }

    /**
     * 单行查询原始数组形式（未经 Entity 包装）
     * 子类如需定制查询逻辑请重写此方法而非 fetch
     */
    public function fetchRaw(string $field, int $cacheTime = 0): ?array
    {
        if (empty($this->where) || (empty($field) && empty($this->joinFields))) {
            throw new TextException(21010);
        }
        $fields = $this->joinFields ? $this->transFields($field) . ',' . $this->joinFields : $this->transFields($field);
        $row = $this->t($this->tableName)
            ->withWhere($this->where)
            ->withJoin($this->joins)
            ->withOrderby($this->order, $this->by)
            ->fetch($fields, $cacheTime);
        if (!empty($row)) {
            if (!is_array($row)) {
                $row = [$field => $row];
            }
            if (!empty($this->respExtraRowFields)) {
                $row = [$row];
                $this->listRowHandle($row);
                $row = $row[0];
            }
        }
        return $row ?: null;
    }

    /**
     * 列表查询：返回 Entity 或 stdClass 数组（取决于 $entityClass）
     */
    public function fetchList(string $field, string $indexField = '', int $cacheTime = 0): array
    {
        $list = $this->fetchListRaw($field, $indexField, $cacheTime);
        return $this->wrapEntityList($list);
    }

    /**
     * 列表查询原始数组形式（未经 Entity 包装）
     */
    public function fetchListRaw(string $field, string $indexField = '', int $cacheTime = 0): array
    {
        if (empty($field)) {
            throw new TextException(21010);
        }
        $field = $this->joinFields ? $this->transFields($field) . ',' . $this->joinFields : $this->transFields($field);
        $list = $this->t($this->tableName)
            ->withWhere($this->where)
            ->withGroupby($this->groupBy)
            ->withOrderby($this->order, $this->by)
            ->withJoin($this->joins)
            ->withLimit($this->pageSize, $this->page)
            ->fetchList($field, $indexField, $cacheTime);
        if (!empty($this->respExtraRowFields)) {
            $this->listRowHandle($list);
        }
        return $list;
    }

    /**
     * 数据行包装为 Entity：$entityClass 非空用专属 Entity，否则用 GenericEntity 兜底
     * 两种情况均返回 EntityAbstract 子类实例，业务侧 toArray/setRelation 等方法始终可用
     */
    protected function wrapEntity(array $data): object
    {
        return $this->entityClass
            ? ($this->entityClass)::fromArray($data)
            : GenericEntity::fromArray($data);
    }

    /**
     * 数据行列表包装为 Entity 数组（保留原始索引）
     */
    protected function wrapEntityList(array $list): array
    {
        if ($this->entityClass) {
            return array_map(fn($item) => ($this->entityClass)::fromArray($item), $list);
        }
        return array_map(fn($item) => GenericEntity::fromArray($item), $list);
    }

    public function pageList(string $fields = '*', int $cacheTime = 0, string $indexField = ''): array
    {
        if (empty($fields)) {
            throw new TextException(21010);
        }
        $data = $this->t($this->tableName)
            ->withWhere($this->where)
            ->withGroupby($this->groupBy)
            ->withOrderby($this->order, $this->by)
            ->withJoin($this->joins)
            ->withLimit($this->pageSize, $this->page)
            ->pageList($this->transFields($fields), $cacheTime, $indexField);
        if (!empty($this->respExtraRowFields)) {
            $this->listRowHandle($data['list']);
        }
        return $data;
    }

    /**
     * 数据有效性检测
     * @param array $data 数据
     * @param int $id 要编辑的信息ID(判断唯一性时用到)
     * @return array
     * @throws TextException
     */
    public function validCheck(array $data, int $id = 0): array
    {
        return $this->formWrite->validCheck($this->formId, $data, $id);
    }

    /**
     * 防止联表查询有冲突，主表字段默认都加main前缀
     * @param $fields
     * @return float|int|string
     */
    protected function transFields($fields)
    {
        if (is_numeric($fields) || $fields == '*') {
            return $fields;
        }
        $arr = [];
        foreach (explode(',', $fields) as $field) {
            if (strpos($field, '.') === false) {
                $arr[] = 'main.' . $field;
            } else {
                $arr[] = $field;
            }
        }
        return implode(',', $arr);
    }

    public function insert(array $data): int
    {
        return $this->t($this->tableName)->insert($data);
    }

    /**
     * 获取某字段对应的某字段的map
     * @param string $columnField
     * @param string $indexField
     * @return array
     * @throws TextException
     */
    public function map(string $columnField, string $indexField): array
    {
        if (empty($columnField) || empty($indexField)) {
            throw new TextException(21010);
        }
        $list = $this->fetchList($columnField . ',' . $indexField);
        return $list ? array_column($list, $columnField, $indexField) : [];
    }
}
