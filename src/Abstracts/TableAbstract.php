<?php
/**
 * 数据库内容相关读写操作
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use App\Core\Forms;
use SlimCMS\Error\TextException;
use SlimCMS\Interfaces\OutputInterface;

abstract class TableAbstract extends ServiceAbstract
{
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
    protected $limit;
    private $tableName = '';
    private $formId = 0;

    protected function initialize()
    {
        $this->tableName = preg_replace('/service$/', '', strtolower(substr(strrchr(get_called_class(), '\\'), 1)));
        $this->formId = (int)self::t('forms')->withWhere(['table' => $this->tableName])->fetch('id');
        if (empty($this->formId)) {
            throw new TextException(21039);
        }
    }

    protected function getData(array $param): array
    {
        $data = [];
        $list = self::t('forms_fields')
            ->withWhere(['formid' => $this->formId, 'available' => 1])
            ->fetchList('identifier,datatype,rules');
        foreach ($list as $v) {
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
    public function add(array $param): OutputInterface
    {
        $data = $this->getData($param);
        if (empty($data)) {
            return self::$output->withCode(21020);
        }
        return Forms::dataSave($this->formId, [], $data);
    }

    /**
     * 修改
     * @param int $id
     * @param array $param
     * @return OutputInterface
     */
    public function edit(int $id, array $param, array $options = []): OutputInterface
    {
        if (empty($id) || empty($param)) {
            return self::$output->withCode(21003);
        }
        $data = $this->getData($param);
        if (empty($data)) {
            return self::$output->withCode(21020);
        }
        $res = Forms::dataView($this->formId, $id);
        if ($res->getCode() != 200) {
            return $res;
        }
        $val = $res->getData()['row'];
        $res = $this->editPreHandle($val, $param, $options);
        if ($res->getCode() != 200) {
            return $res;
        }
        return Forms::dataSave($this->formId, $val, $data);
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
        return self::$output->withCode(200);
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
            return self::$output->withCode(21003);
        }
        return Forms::dataDel($this->formId, [$id]);
    }

    /**
     * 详细
     * @param int $id 信息ID
     * @param string $fields 读取字段，多个字段用,隔开
     * @param string $respExtraRowFields 自定义返回值对应的key，多个key用,隔开
     * @param array $param 其它自定义参数
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function detail(int $id, string $fields, string $respExtraRowFields = '', array $param = []): OutputInterface
    {
        if (empty($id) || empty($fields)) {
            return self::$output->withCode(21002);
        }
        $res = Forms::dataView($this->formId, $id, $fields);
        if ($res->getCode() != 200) {
            return $res;
        }
        $data = (array)$res->getData()['row'];
        $this->listRowHandle($data);
        return self::$output->withCode(200)->withData($data);
    }

    /**
     * 生成筛选条件
     * @param array $param
     * @return $this
     * @throws TextException
     */
    public function withWhere(array $param): self
    {
        $clone = clone $this;
        $class = '\App\Model\req\\' . ucfirst($this->tableName) . 'Req';
        if (!empty($class) && method_exists($class, 'instance') && is_callable([$class, 'instance'])) {
            $callback = $class . '::instance';
            $req = $callback()->getReq();
            $clone->where = $req->getWhere($param);
            $clone->joins = $req->getJoins();
        }
        return $clone;
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
        $clone->order = $field;
        $clone->by = $direction;
        $clone->orderForce = $orderForce;
        return $clone;
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

    public function withrespExtraFields(string $fields): self
    {
        $clone = clone $this;
        $clone->respExtraFields = $fields;
        return $clone;
    }

    public function withLimit($limit): self
    {
        $clone = clone $this;
        $clone->limit = $limit;
        return $clone;
    }

    /**
     * 列表
     * @param array $param
     * @return OutputInterface
     */
    public function list(string $fields = 'id,createtime', int $page = 1, int $pagesize = 30): OutputInterface
    {
        $params = [
            'fid' => $this->formId,
            'page' => $page,
            'pagesize' => $pagesize,
            'fields' => self::transFields($fields),
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
        $res = Forms::dataList($params);
        if ($res->getCode() != 200) {
            return $res;
        }
        $data = $res->getData();
        if (!empty($this->respExtraRowFields)) {
            foreach ($data['list'] as &$v) {
                $this->listRowHandle($v);
            }
        }
        $val = [
            'list' => aval($data, 'list'),
            'count' => aval($data, 'count'),
            'maxpages' => aval($data, 'maxpages', 0),
            'page' => $page,
            'pagesize' => $pagesize
        ];
        if (!empty($this->respExtraFields)) {
            $val = array_merge($val, $this->listHandle($data));
        }
        return self::$output->withCode(200)->withData($val);
    }

    /**
     * 列表数据处理
     * @param array $data
     * @return array
     */
    protected function listHandle(&$data)
    {
        $class = '\App\Model\resp\\' . ucfirst($this->tableName) . 'Resp';
        if (!empty($class) && method_exists($class, 'instance') && is_callable([$class, 'instance'])) {
            $callback = $class . '::instance';
            return $callback()->getRespExtraData($data, $this->respExtraFields);
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
        $class = '\App\Model\resp\\' . ucfirst($this->tableName) . 'Resp';
        if (!empty($class) && method_exists($class, 'instance') && is_callable([$class, 'instance'])) {
            $callback = $class . '::instance';
            $callback()->getRespExtraRowData($data, $this->respExtraRowFields);
        }
    }

    public function batchDelete(): int
    {
        if (empty($this->where)) {
            throw new TextException(21010);
        }
        return self::t($this->tableName)->withWhere($this->where)->delete();
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
        return self::t($this->tableName)->withWhere($this->where)->update($value);
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
        return self::t($this->tableName)->withWhere($id)->update($value);
    }

    /**
     * 数量统计
     * @param string $fields
     * @param int $cacheTime
     * @return int
     * @throws TextException
     */
    public function count(string $fields = '*', int $cacheTime = 0): int
    {
        return self::t($this->tableName)->withWhere($this->where)->count($fields, $cacheTime);
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
        return (float)self::t($this->tableName)->withWhere($this->where)->sum($field);
    }

    public function fetchColumn(string $field, string $func)
    {
        if (empty($field) || empty($func)) {
            throw new TextException(21010);
        }
        return self::t($this->tableName)->withWhere($this->where)->fetchColumn($field, $func);
    }


    public function fetch(string $field, int $cacheTime = 0)
    {
        if (empty($this->where) || empty($field)) {
            throw new TextException(21010);
        }
        $row = self::t($this->tableName)->withWhere($this->where)->fetch($field, $cacheTime);
        !empty($row) && is_array($row) && $this->listRowHandle($row);
        return $row;
    }

    public function fetchList(string $field, string $indexField = '', int $cacheTime = 0): array
    {
        if (empty($field)) {
            throw new TextException(21010);
        }
        $list = self::t($this->tableName)
            ->withWhere($this->where)
            ->withGroupby($this->groupBy)
            ->withOrderby($this->order, $this->by)
            ->withJoin($this->joins)
            ->withLimit($this->limit)
            ->fetchList(self::transFields($field), $indexField, $cacheTime);
        if (!empty($this->respExtraRowFields)) {
            foreach ($list as &$v) {
                $this->listRowHandle($v);
            }
        }
        return $list;
    }

    public function pageList(string $fields = '*', int $page = 1, int $pagesize = 30, int $cacheTime = 0, string $indexField = ''): array
    {
        if (empty($field)) {
            throw new TextException(21010);
        }
        return self::t($this->tableName)
            ->withWhere($this->where)
            ->withGroupby($this->groupBy)
            ->withOrderby($this->order, $this->by)
            ->withJoin($this->joins)
            ->pageList($page, self::transFields($fields), $pagesize, $cacheTime, $indexField);
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
        return Forms::validCheck($this->formId, $data, $id);
    }

    protected static function transFields(string $fields): string
    {
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
}
