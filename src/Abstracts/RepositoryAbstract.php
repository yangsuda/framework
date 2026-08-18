<?php
/**
 * 数据库内容相关读写操作
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Respect\Validation\Exceptions\ValidationException;
use Slim\App;
use SlimCMS\Core\Forms;
use SlimCMS\Error\TextException;
use SlimCMS\Interfaces\OutputInterface;

abstract class RepositoryAbstract extends BaseAbstract
{
    use \SlimCMS\Traits\table;

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
    protected $auth;
    protected $query = [];//查询参数
    private $tableName = '';
    private $formId = 0;

    public function __construct(App $app, ServerRequestInterface $request = null)
    {
        parent::__construct($app, $request);
        $this->initialize();
    }

    protected function initialize()
    {
        $this->tableName = preg_replace('/repository$/', '', strtolower(substr(strrchr(get_called_class(), '\\'), 1)));
        $this->formId = (int)$this->t('forms')->withWhere(['table' => $this->tableName])->fetch('id');
        if (empty($this->formId)) {
            throw new TextException(21039);
        }
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

    protected function forms(): Forms
    {
        return $this->i(Forms::class);
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
        return $this->forms()->dataSave($this->formId, [], $data);
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
        $res = $this->forms()->dataView($this->formId, $id);
        if ($res->getCode() != 200) {
            return $res;
        }
        $val = $res->getData()['row'];
        $res = $this->editPreHandle($val, $param, $options);
        if ($res->getCode() != 200) {
            return $res;
        }
        return $this->forms()->dataSave($this->formId, $val, $data);
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
        return $this->forms()->dataDel($this->formId, [$id]);
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
        $res = $this->forms()->dataView($this->formId, $id, $fields);
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

    public function withLimit($limit): self
    {
        $clone = clone $this;
        $clone->limit = $limit;
        return $clone;
    }

    public function getLimit()
    {
        return $this->limit;
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
     * 列表
     * @param string $fields 字段
     * @param int $page 页码
     * @param int $pagesize 每页数量
     * @return array
     */
    public function list(string $fields = 'id,createtime', int $page = 1, int $pagesize = 30): array
    {
        $params = [
            'fid' => $this->formId,
            'page' => $page,
            'pagesize' => $pagesize,
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
        $res = $this->forms()->dataList($params);
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
            'page' => $page,
            'pagesize' => $pagesize
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
        return $this->t($this->tableName)->withWhere($id)->update($value);
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


    public function fetch(string $field, int $cacheTime = 0)
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
        return $row;
    }

    public function fetchList(string $field, string $indexField = '', int $cacheTime = 0): array
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
            ->withLimit($this->limit)
            ->fetchList($field, $indexField, $cacheTime);
        if (!empty($this->respExtraRowFields)) {
            $this->listRowHandle($list);
        }
        return $list;
    }

    public function pageList(string $fields = '*', int $page = 1, int $pagesize = 30, int $cacheTime = 0, string $indexField = ''): array
    {
        if (empty($field)) {
            throw new TextException(21010);
        }
        $data = $this->t($this->tableName)
            ->withWhere($this->where)
            ->withGroupby($this->groupBy)
            ->withOrderby($this->order, $this->by)
            ->withJoin($this->joins)
            ->pageList($page, $this->transFields($fields), $pagesize, $cacheTime, $indexField);
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
        return $this->forms()->validCheck($this->formId, $data, $id);
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
