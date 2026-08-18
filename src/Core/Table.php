<?php

/**
 * DB层数据读写类
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use SlimCMS\Abstracts\BaseAbstract;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\FileCache;
use SlimCMS\Helper\Str;
use SlimCMS\Interfaces\DatabaseInterface;

class Table extends BaseAbstract
{
    /**
     * 表名
     * @var string
     */
    protected $tableName = '';

    /**
     * 分表名
     * @var string
     */
    protected $extendName = '';

    /**
     * 数据库连接实例
     * @var mixed|DatabaseInterface
     */
    protected $db;

    /**
     * 某条数据缓存时间
     * @var int
     */
    protected $fetchTTL = 952000;

    /**
     * 查询条件
     * @var array
     */
    protected $where = '';

    /**
     * 查询条件是否纯数字,>0为对应数字
     * @var bool
     */
    protected $whereIsNumber = 0;

    /**
     * 联表查SQL
     * @var string
     */
    protected $join = '';

    /**
     * 查询数量
     * @var string
     */
    protected $limit = '';

    /**
     * 表名前缀
     * @var string
     */
    private $tablepre = '';

    /**
     * 排序
     * @var string
     */
    protected $orderby = ' order by main.id desc ';

    protected $groupby = '';

    public function __construct(App $app, ServerRequestInterface $request = null)
    {
        parent::__construct($app, $request);
        $this->db = $this->container->get(DatabaseInterface::class);
        $this->tablepre = $this->setting['db']['tablepre'];
    }

    /**
     * 数据库操作实例
     * @return mixed|DatabaseInterface
     */
    public function db()
    {
        return $this->db;
    }

    /**
     * 设置表名
     * @param string $tableName
     * @param string|null $extendName
     * @return $this
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function setTableName(string $tableName, string $extendName = null): self
    {
        $this->tableName = $this->tablepre . $tableName;
        $this->extendName = $extendName;
        $extendName && $this->subtable($extendName);
        return $this;
    }

    public function getTableName(): string
    {
        return $this->tableName . $this->extendName;
    }

    /**
     * 分表操作（在调用父构造函数之前调用）
     * @param string $index 表名后缀
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    private function subtable(string $index): bool
    {
        $tableName = $this->tableName;
        $index = preg_replace('/[^a-zA-Z0-9_]/', '', $index);
        $subTableName = $tableName . $index;
        $cachekey = __FUNCTION__ . '_' . $subTableName;
        $data = FileCache::get($cachekey);
        if (empty($data)) {
            if ($this->db->fetch("SHOW TABLES LIKE '" . $subTableName . "'")) {
                return false;
            }
            //防止新生成的表单自增ID不连续
            if ($this->db->fetch("SHOW TABLES LIKE '" . $tableName . ($index - 1) . "'")) {
                $sql = "show create table " . $tableName . ($index - 1);
                $search = $tableName . ($index - 1);
            } else {
                $sql = "show create table " . $tableName;
                $search = $tableName;
            }
            $row = $this->db->fetch($sql);
            $sql = str_replace($search, $subTableName, $row['Create Table']);
            $query = $this->db->query($sql);
            $this->db->affectedRows($query);
            FileCache::set($cachekey, 1, 864000000);
        }
        return true;
    }

    /**
     * 获取查询SQL
     * @param string $fields
     * @return string
     */
    protected function selectSQL(string $fields): string
    {
        $sql = 'SELECT ' . $fields . ' FROM ' . $this->getTableName() . ' main ' .
            $this->join . $this->where . $this->groupby . $this->orderby . $this->limit;
        return $sql;
    }

    /**
     * 数量统计
     * @param string $fields
     * @param int $cacheTime
     * @return int
     */
    public function count(string $fields = '*', int $cacheTime = 0): int
    {
        if ($this->redis->isAvailable()) {
            $cacheKey = $this->cacheKey(__FUNCTION__) . $this->md5key(func_get_args());
            $count = $cacheTime ? $this->redis->get($cacheKey) : 0;
        }
        if (empty($count)) {
            $fields = $fields ?: '*';
            $sql = $this->selectSQL('count(' . $fields . ')');
            if ($this->groupby) {
                $sql = 'select count(*) from (' . $sql . ') as tmp';
            }
            $count = $this->db->fetchColumn($sql);
            $this->redis->isAvailable() && $cacheTime && $this->redis->set($cacheKey, $count, $cacheTime);
        }
        return (int)$count;
    }

    /**
     * 清除fetch缓存
     * @param int $indexid
     * @return int|null
     */
    public function clearFetchCache(int $indexid)
    {
        $cachekey = $this->cacheKey($indexid);
        return $this->redis->del($cachekey);
    }

    /**
     * 获取某条记录
     * @param string $fields
     * @param int $cacheTime
     * @return array|bool|mixed|string|null
     */
    public function fetch(string $fields = '*', int $cacheTime = 0)
    {
        if ($this->redis->isAvailable() && !$this->join) {
            if ($this->whereIsNumber) {
                $indexid = $this->whereIsNumber;
            } else {
                $key = $this->cacheKey(__FUNCTION__) . $this->md5key();
                $indexid = $cacheTime ? $this->redis->get($key) : '';
                if (empty($indexid)) {
                    $sql = $this->selectSQL('id');
                    $indexid = $this->db->fetchColumn($sql);
                    $cacheTime && $this->redis->set($key, $indexid, $cacheTime);
                }
            }
            if (empty($indexid)) {
                return null;
            }
            $cachekey = $this->cacheKey($indexid);
            $data = $this->redis->get($cachekey);
            if (empty($data)) {
                $data = $this->db->fetch('SELECT * FROM ' . $this->getTableName() . ' main WHERE id=' . $indexid);
                $this->fetchTTL && $this->redis->set($cachekey, $data, $this->fetchTTL);
            }
        } else {
            $data = [];
            if (!empty($this->where)) {
                $sql = $this->selectSQL('*');
                $data = $this->db->fetch($sql);
            }
        }

        if ($fields == '*') {
            return $data;
        }
        if (preg_match('/,/', $fields)) {
            $fields = explode(',', $fields);
            $row = [];
            foreach ($fields as $v) {
                $v = str_replace('main.', '', $v);
                if (preg_match('/ as /i', $v)) {
                    list($v, $v1) = explode(' as ', $v);
                    if (isset($data[$v])) {
                        $row[trim($v1)] = trim($data[$v]);
                    }
                } else {
                    if (isset($data[$v])) {
                        $row[$v] = $data[$v];
                    }
                }
            }
            return $row;
        }
        $fields = str_replace('main.', '', $fields);
        if (isset($data[$fields])) {
            return $data[$fields];
        }
    }

    /**
     * 列表数据
     * @param string $fields
     * @param string $indexField
     * @param int $cacheTime
     * @return array
     */
    public function fetchList(string $fields = '*', string $indexField = '', int $cacheTime = 0): array
    {
        $func = function ($fields, $indexField, $cacheTime) {
            if (preg_match('/distinct /i', $fields) || $this->join) {
                $sql = $this->selectSQL($fields);
                $list = $this->db->fetchList($sql);
            } else {
                $field1 = $cacheTime ? 'id' : (strpos($fields, ',') ? implode(',', $this->quoteField(explode(',', $fields))) : $fields);
                $sql = $this->selectSQL($field1);
                $list = $this->db->fetchList($sql, $indexField);
                if ($this->redis->isAvailable()) {
                    foreach ($list as $k => $v) {
                        $data = !empty($v['id']) ? $this->withWhere($v['id'])->fetch($fields) : [];
                        if (!strpos($fields, ',') && $fields != '*' && $data) {
                            $fields = str_replace('main.', '', $fields);
                            $data = [$fields => $data];
                        }
                        $list[$k] = $data ?: $v;
                    }
                }
            }
            return $list;
        };
        if ($this->redis->isAvailable()) {
            if ($this->join) {
                $cacheTime = 0;
            }
            $cacheKey = $this->cacheKey(__FUNCTION__) . $this->md5key(func_get_args());
            $list = $cacheTime ? $this->redis->get($cacheKey) : [];
            if (empty($list)) {
                $list = $func($fields, $indexField, $cacheTime);
                $cacheTime && $this->redis->set($cacheKey, $list, $cacheTime);
            }
            return $list;
        }
        return $func($fields, $indexField, $cacheTime);

    }

    /**
     * 获取某一列数据
     * @param string $field
     * @param int $cacheTime
     * @return array
     */
    public function onefieldList(string $field = 'id', int $cacheTime = 0): array
    {
        $func = function ($field, $cacheTime) {
            $field1 = $cacheTime ? 'id' : $field;
            $sql = $this->selectSQL($field1);
            $list = $this->db->fetchList($sql);
            $arr = [];
            foreach ($list as $k => $v) {
                //如果开启缓存，读取缓存中数据
                if ($cacheTime) {
                    $arr[] = $this->withWhere($v['id'])->fetch($field);
                } else {
                    $arr[] = $v[$field];
                }
            }
            return $arr;
        };
        if ($this->redis->isAvailable()) {
            if ($this->join) {
                $cacheTime = 0;
            }
            $cacheKey = $this->cacheKey(__FUNCTION__) . $this->md5key(func_get_args());
            $list = $cacheTime ? $this->redis->get($cacheKey) : [];
            if (empty($list)) {
                $list = $func($field, $cacheTime);
                $cacheTime && $this->redis->set($cacheKey, $list, $cacheTime);
            }
            return $list;
        }
        return $func($field, $cacheTime);
    }

    /**
     * 设置联表查SQL
     * @param $join
     * @return Table
     */
    public function withJoin(array $join): Table
    {
        if (!empty($join)) {
            $clone = clone $this;
            $clone->join = ' left join ' . $this->tablepre . implode(' left join ' . $this->tablepre, $join);
            return $clone;
        }
        return $this;
    }

    /**
     * 设置读取数据数量
     * @param $limit
     * @return Table
     */
    public function withLimit($limit): Table
    {
        if (!empty($limit)) {
            $clone = clone $this;
            $clone->limit = strpos((string)$limit, 'limit') !== false ? ' ' . $limit : ' limit ' . $limit;
            return $clone;
        }
        return $this;
    }

    /**
     * 设置排序
     * @param string $order
     * @param string $way
     * @return Table
     */
    public function withOrderby(string $order = '', string $way = 'desc'): Table
    {
        $clone = clone $this;
        $order = $order ?: 'main.id';
        if (!preg_match('/^group by /i', $order)) {
            $order = ' order by ' . $order;
        }
        $way = $way == 'asc' ? 'asc' : 'desc';
        $clone->orderby = ' ' . $order . ' ' . $way;
        return $clone;
    }

    public function withGroupby(string $field = ''): Table
    {
        $clone = clone $this;
        $field && $clone->groupby = ' group by ' . $field . ' ';
        return $clone;
    }

    /**
     * 更新fetch缓存
     * @param int $id
     * @param array $data
     */
    public function updateFetchCache(int $id, array $data)
    {
        if ($this->redis->isAvailable()) {
            $cachekey = $this->cacheKey($id);
            $cacheData = $this->redis->get($cachekey);
            if ($cacheData) {
                foreach ($data as $key => $value) {
                    $matches = [];
                    preg_match('/^(#@#){1}[A-Za-z]{2,}([\w])*(\+|\-)([\d.]{1,20})$/i', (string)$value, $matches);
                    if ($matches) {
                        if ($matches[3] == '+') {
                            $data[$key] = $cacheData[$key] + (int)$matches[4];
                        } elseif ($matches[3] == '-') {
                            $data[$key] = $cacheData[$key] - (int)$matches[4];
                        }
                    }
                }
                $cacheData = array_merge($cacheData, $data);
                $this->redis->set($cachekey, $cacheData, $this->fetchTTL);
            }
        }
    }

    /**
     * 修改操作
     * @param array $data
     * @return int
     */
    public function update(array $data): int
    {
        if (!empty($data)) {
            if ($this->redis->isAvailable()) {
                $row = $this->fetchList('main.id');
                foreach ($row as $v) {
                    $this->updateFetchCache((int)$v['id'], $data);
                }
            }
            if (!$this->where) {
                return 0;
            }
            $sql = 'UPDATE ' . $this->getTableName() . ' main SET ' . $this->implodeSave($data) . $this->where;
            $query = $this->db->query($sql);
            return $this->db->affectedRows($query);
        }
        return 0;
    }

    /**
     * 删除操作
     * @return int
     */
    public function delete(): int
    {
        if ($this->redis->isAvailable()) {
            $row = $this->fetchList('main.id');
            foreach ($row as $v) {
                $cachekey = $this->cacheKey($v['id']);
                $this->redis->del($cachekey);
            }
        }
        if (!$this->where) {
            return 0;
        }
        $query = $this->db->query('DELETE main FROM ' . $this->getTableName() . ' main ' . $this->where);
        return $this->db->affectedRows($query);
    }

    /**
     * 插入数据
     * @param array $data
     * @param bool $returnID
     * @param bool $replace
     * @return int
     */
    public function insert(array $data, bool $returnID = false, bool $replace = false): int
    {
        $sql = $this->implodeSave($data);
        $cmd = $replace ? 'REPLACE INTO ' : 'INSERT INTO ';
        $query = $this->db->query($cmd . $this->getTableName() . ' set ' . $sql);
        if ($returnID) {
            return (int)$this->db->insertId();
        }
        return $this->db->affectedRows($query);
    }

    /**
     * 条件处理
     * @param $val
     * @return Table
     * @throws TextException
     */
    public function withWhere($val): Table
    {
        $this->whereIsNumber = 0;
        if (empty($val)) {
            $where = '';
        } elseif (is_array($val)) {
            $where = $this->implode($val, 'and');
        } elseif (is_numeric($val)) {
            $this->whereIsNumber = $val;
            $where = $this->field('id', $val);
        } else {
            $where = str_replace(' where ', '', $val);
        }
        $clone = clone $this;
        $clone->where = $where ? ' where ' . $where : '';
        return $clone;
    }

    public function implode(array $array, string $glue = ','): string
    {
        $sql = $comma = '';
        $glue = ' ' . trim($glue) . ' ';
        foreach ($array as $k => $v) {
            if (is_numeric($k)) {
                $sql .= $comma . $v;
            } elseif (is_array($v)) {
                $sql .= $comma . $this->field($this->quoteField($k), $v);
            } else {
                $sql .= $comma . $this->quoteField($k) . '=' . $this->quote($v);
            }
            $comma = $glue;
        }
        return $sql;
    }

    protected function quote($str, $noarray = false)
    {
        if (is_string($str)) {
            if (preg_match('/^(#@#){1}[A-Za-z]{2,}([\w])*(\+|\-)([\d.]{1,20})$/i', $str)) {
                return addcslashes(preg_replace('/^#@#/i', '', $str), "\n\r\\'\"\032");
            }
            return '\'' . addcslashes($str, "\n\r\\'\"\032") . '\'';
        }

        if (is_int($str) or is_float($str)) {
            return '\'' . $str . '\'';
        }

        if (is_array($str)) {
            if ($noarray === false) {
                foreach ($str as &$v) {
                    $v = $this->quote($v, true);
                }
                return $str;
            }
            return '\'\'';
        }

        if (is_bool($str)) {
            return $str ? '1' : '0';
        }
        return '\'\'';
    }

    protected function quoteField($field)
    {
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $field[$k] = $this->quoteField($v);
            }
        } else {
            if (strpos($field, '`') !== false) {
                $field = str_replace('`', '', $field);
            }
            if (
                !empty($this->settings['security']['querysafe']['exceptFunction']) &&
                preg_match('/' . $this->settings['security']['querysafe']['exceptFunction'] . '/i', $field)
            ) {
                //^转,防止参数被当成字段拆分
                return str_replace('^', ',', $field);
            }
            if (preg_match('/\./', $field)) {
                list($pre, $field) = explode('.', $field);
                $field = trim($field);
                if (strpos($field, ' ')) {
                    $field = $pre . '.`' . strstr($field, ' ', true) . '`' . strstr($field, ' ');
                } else {
                    $field = $pre . '.`' . $field . '`';
                }
            } else {
                $field = '`' . $field . '`';
            }
        }
        return $field;
    }

    public function field($field, $val, $glue = '=')
    {
        $field = $this->quoteField($field);
        if (empty($val) && is_array($val)) {
            $val = '';
        }
        if (is_array($val)) {
            $glue = $glue == 'notin' ? 'notin' : 'in';
        } elseif ($glue == 'in') {
            $glue = '=';
        }

        switch ($glue) {
            case '=':
                return $field . $glue . $this->quote($val);
            case '-':
            case '+':
                return $field . '=' . $field . $glue . $this->quote((string)$val);
            case '|':
            case '&':
            case '^':
                return $field . '=' . $field . $glue . $this->quote($val);
            case '>':
            case '<':
            case '<>':
            case '<=':
            case '>=':
                if (is_int($val) || is_float($val)) {
                    return $field . $glue . $val;
                }
                return $field . $glue . $this->quote($val);
            case 'unlike':
            case 'like':
                $not = $glue == 'unlike' ? ' not ' : '';
                if (preg_match('/%/', $val)) {
                    return $field . $not . ' LIKE(' . $this->quote($val) . ')';
                }
                return $field . $not . ' LIKE(' . $this->quote('%' . $val . '%') . ')';
            case 'in':
            case 'notin':
                $val = $val ? implode(',', $this->quote($val)) : '\'\'';
                return $field . ($glue == 'notin' ? ' NOT' : '') . ' IN(' . $val . ')';
            case 'find':
                return 'FIND_IN_SET(' . $this->quote($val) . ', ' . $field . ')>0';
            case 'nofind':
                return 'FIND_IN_SET(' . $this->quote($val) . ', ' . $field . ')<1';
            case 'findMult':
                if (empty($val) || !is_array($val) && !strpos($val, ',')) {
                    return 'FIND_IN_SET(' . $this->quote($val) . ', ' . $field . ')>0';
                }
                $val = is_array($val) ? $val : explode(',', $val);
                $arr = [];
                foreach ($val as $v1) {
                    $arr[] = 'FIND_IN_SET(' . $this->quote($v1) . ', ' . $field . ')>0';
                }
                return '(' . implode(' or ', $arr) . ')';
            case 'nofindMult':
                if (empty($val) || !is_array($val) && !strpos($val, ',')) {
                    return 'FIND_IN_SET(' . $this->quote($val) . ', ' . $field . ')<1';
                }
                $val = is_array($val) ? $val : explode(',', $val);
                $arr = [];
                foreach ($val as $v1) {
                    $arr[] = 'FIND_IN_SET(' . $this->quote($v1) . ', ' . $field . ')<1';
                }
                return '(' . implode(' or ', $arr) . ')';
            case 'between':
                list($min, $max) = explode(',', $val);
                $min = preg_replace('/[^\d.-]/', '', $min);
                $max = preg_replace('/[^\d.-]/', '', $max);
                return '(' . $field . ' between  ' . $min . ' and ' . $max . ')';
            case 'regexp':
                return $field . ' REGEXP ' . $this->quote($val);
            default:
                throw new TextException(21058, '', 'SQL');
        }
    }

    /**
     * 增改用到
     * @param array $array
     * @param string $glue
     * @return string
     */
    protected function implodeSave(array $array, string $glue = ','): string
    {
        $sql = $comma = '';
        $glue = ' ' . trim($glue) . ' ';
        foreach ($array as $k => $v) {
            if (is_array($v)) {
                $v = serialize($v);
            }
            $sql .= $comma . $this->quoteField($k) . '=' . $this->quote($v);
            $comma = $glue;
        }
        return $sql;
    }

    /**
     * 某字段数量统计
     * @param string $field
     * @return string
     */
    public function sum(string $field)
    {
        return $this->fetchColumn($field, 'sum');
    }

    /**
     * 某字段平均数
     * @param string $field
     * @return string
     */
    public function avg(string $field)
    {
        return $this->fetchColumn($field, 'avg');
    }

    /**
     * 获取某字段通过某函数处理后的数据
     * @param string $field
     * @param string $func
     * @return string
     */
    public function fetchColumn(string $field, string $func)
    {
        $sql = $this->selectSQL($func . '(' . $field . ')');
        return $this->db->fetchColumn($sql);
    }

    /**
     * 返回分页列表数据
     * @param int $page
     * @param string $fields
     * @param int $pagesize
     * @param int $cacheTime
     * @param string $indexField
     * @return array
     */
    public function pageList(int $page = 1, string $fields = '*', int $pagesize = 30, int $cacheTime = 0, string $indexField = ''): array
    {
        $page = max(1, $page);
        $fields = $fields ?: '*';
        $pagesize = $pagesize ?: 30;
        $count = $this->count('*', $cacheTime);
        $maxpages = (int)ceil($count / $pagesize);
        $page = $page > $maxpages ? $maxpages : $page;
        $start = ($page - 1) * $pagesize;
        $this->limit = ' limit ' . $start . ',' . $pagesize;

        if (empty($count)) {
            $list = [];
        } else {
            $list = $this->fetchList($fields, $indexField, $cacheTime);
        }
        return ['list' => $list, 'count' => $count, 'maxpages' => $maxpages, 'page' => $page, 'pagesize' => $pagesize];
    }


    /**
     * 将缓存KEY中含有的时间戳后3位改成000，否则缓存会一直生成，失去缓存意义
     */
    protected function md5key(array $condition = []): string
    {
        $condition[] = $this->where;
        $condition[] = $this->join;
        return Str::md5key($condition);
    }
}
