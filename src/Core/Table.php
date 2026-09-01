<?php

/**
 * DB层数据读写类
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core;

use Slim\App;
use SlimCMS\Abstracts\BaseAbstract;
use SlimCMS\Core\Form\TableHookInterface;
use SlimCMS\Core\Form\TableHookTrait;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\FileCache;
use SlimCMS\Helper\Str;
use SlimCMS\Interfaces\DatabaseInterface;

class Table extends BaseAbstract implements TableHookInterface
{
    use TableHookTrait;
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
     * [SQL安全改造] 查询条件绑定参数（配合 where 中的 ? 占位符）
     * @var array
     */
    protected $whereParams = [];

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

    protected Redis $redis;

    protected $setting;//站点初始化参数

    protected int $page = 1;
    protected int $pageSize = 30;

    public function __construct(App $app, Redis $redis)
    {
        parent::__construct($app);
        $this->redis = $redis;
        $this->setting = $this->container->get('settings');
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
        $clone = clone $this;
        $clone->tableName = $this->tablepre . $tableName;
        $clone->extendName = $extendName;
        $extendName && $clone->subtable($extendName);
        return $clone;
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
            $sql = $sql = 'SELECT count(' . $fields . ') FROM ' . $this->getTableName() . ' main ' .
                $this->join . $this->where . $this->groupby . $this->orderby;
            if ($this->groupby) {
                $sql = 'select count(*) from (' . $sql . ') as tmp';
            }
            $count = $this->db->fetchColumn($sql, 0, $this->whereParams); // [SQL安全改造] 透传绑定参数
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
            $key = $this->cacheKey(__FUNCTION__) . $this->md5key();
            $indexid = $cacheTime ? $this->redis->get($key) : '';
            if (empty($indexid)) {
                $sql = $this->selectSQL('id');
                $indexid = $this->db->fetchColumn($sql, 0, $this->whereParams); // [SQL安全改造] 透传绑定参数
                $cacheTime && $this->redis->set($key, $indexid, $cacheTime);
            }
            $data = $indexid ? $this->fetchById((int)$indexid, $fields) : null;
            return isset($data[$fields]) ? $data[$fields] : $data;
        }
        $data = [];
        if (!empty($this->where)) {
            $sql = $this->selectSQL($fields);
            $data = $this->db->fetch($sql, $this->whereParams); // [SQL安全改造] 透传绑定参数
        }
        $fields = str_replace('main.', '', $fields);
        if (isset($data[$fields])) {
            return $data[$fields];
        }
        return $data;
    }

    /**
     * 获取某条记录
     * @param int $id
     * @param string $fields
     * @return array
     */
    private function fetchById(int $id, string $fields): array
    {
        if (empty($id) || empty($fields)) {
            return [];
        }
        $cachekey = $this->cacheKey($id);
        $data = $this->redis->isAvailable() ? $this->redis->get($cachekey) : [];
        if (empty($data)) {
            $data = $this->db->fetch('SELECT * FROM ' . $this->getTableName() . ' WHERE id=?', [$id]);
            $this->fetchTTL && $this->redis->set($cachekey, $data, $this->fetchTTL);
        }
        if ($fields == '*') {
            return $data;
        }
        $fields = explode(',', str_replace('main.', '', $fields));
        $row = [];
        foreach ($fields as $v) {
            if (isset($data[$v])) {
                $row[$v] = $data[$v];
            }
        }
        return $row;
    }

    /**
     * 生成缓存KEY
     * @param $key
     * @param mixed ...$param
     * @return string
     */
    protected function cacheKey($key, ...$param): string
    {
        return get_called_class() . ':' . $this->getTableName() . ':' . $key . ':' . Str::md5key($param);
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
                $list = $this->db->fetchList($sql, '', $this->whereParams); // [SQL安全改造] 透传绑定参数
            } else {
                $field1 = $cacheTime ? 'id' : (strpos($fields, ',') ? implode(',', $this->quoteField(explode(',', $fields))) : $fields);
                $sql = $this->selectSQL($field1);
                $list = $this->db->fetchList($sql, $indexField, $this->whereParams); // [SQL安全改造] 透传绑定参数
                if ($this->redis->isAvailable()) {
                    foreach ($list as &$v) {
                        !empty($v['id']) && $v = $this->fetchById((int)$v['id'], $fields);
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
        $list = $this->fetchList($field);
        return array_column($list, $field);
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
     * 设置分页
     * @param int $pageSize
     * @param int $page
     * @return $this
     */
    public function withLimit(int $pageSize = 30, int $page = 1): Table
    {
        if ($pageSize < 1) {
            return $this;
        }
        $clone = clone $this;
        $page = max(1, $page);
        $start = ($page - 1) * $pageSize;
        $clone->page = $page;
        $clone->pageSize = $pageSize;
        $clone->limit = ' limit ' . $start . ',' . $pageSize;
        return $clone;
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
        $order = trim($order ?: 'main.id');
        // [SQL安全改造] 排序字段仅允许字母数字反引号点空格，rand()为框架内置随机排序特殊放行
        if ($order != 'rand()' && (!preg_match('/^[\w`.,\s]+$/i', $order) || stripos($order, 'union') !== false || stripos($order, 'select') !== false)) {
            throw new TextException(21058, '', 'SQL');
        }
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
        // [SQL安全改造] 分组字段仅允许字母数字反引号点空格，非法值忽略
        if ($field && preg_match('/^[\w`.,\s]+$/i', $field)) {
            $clone->groupby = ' group by ' . $field . ' ';
        }
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
            if (!$this->where) {
                return 0;
            }
            // [SQL安全改造] 先保存WHERE条件与绑定参数，防止下方fetchList内部withWhere重置whereParams造成参数错位
            $where = $this->where;
            $whereParams = $this->whereParams;
            if ($this->redis->isAvailable()) {
                $row = $this->fetchList('main.id');
                foreach ($row as $v) {
                    $this->updateFetchCache((int)$v['id'], $data);
                }
            }
            // [SQL安全改造] 记录WHERE参数数，SET参数后入栈，执行时按SQL顺序重排
            $whereCount = count($whereParams);
            $sql = 'UPDATE ' . $this->getTableName() . ' main SET ' . $this->implodeSave($data) . $where;
            $setParams = array_slice($this->whereParams, $whereCount);
            $whereParams = array_slice($this->whereParams, 0, $whereCount);
            $query = $this->db->query($sql, array_merge($setParams, $whereParams));
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
        if (!$this->where) {
            return 0;
        }
        // [SQL安全改造] 先保存WHERE条件与绑定参数，防止下方fetchList内部withWhere重置whereParams造成参数错位
        $where = $this->where;
        $params = $this->whereParams;
        if ($this->redis->isAvailable()) {
            $row = $this->fetchList('main.id');
            foreach ($row as $v) {
                $cachekey = $this->cacheKey($v['id']);
                $this->redis->del($cachekey);
            }
        }
        // [兼容性] 单表DELETE在MySQL 5.x不支持别名，改用多表DELETE兼容语法（5.x/8.x均支持）
        $query = $this->db->query('DELETE main FROM ' . $this->getTableName() . ' main ' . $where, $params); // [SQL安全改造] 透传绑定参数
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
        // [SQL安全改造] 只取本次INSERT产生的SET参数
        $before = count($this->whereParams);
        $sql = $this->implodeSave($data);
        $params = array_slice($this->whereParams, $before);
        $cmd = $replace ? 'REPLACE INTO ' : 'INSERT INTO ';
        $query = $this->db->query($cmd . $this->getTableName() . ' set ' . $sql, $params);
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
    public function withWhere(array $val): Table
    {
        $clone = clone $this;
        $clone->whereParams = []; // [SQL安全改造] 每次重建条件时重置绑定参数，防止残留
        $clone->where = !empty($val) ? ' where ' . $clone->implode($val, 'and') : '';
        return $clone;
    }

    public function implode(array $array, string $glue = ','): string
    {
        $sql = $comma = '';
        $glue = ' ' . trim($glue) . ' ';
        foreach ($array as $k => $v) {
            if (is_numeric($k)) {
                // [SQL安全改造] 支持 ['field' => ['glue', $val]] 惰性条件（由 field 在 implode 时收集参数，顺序正确）
                if (is_array($v) && count($v) == 1) {
                    $f = key($v);
                    $cond = reset($v);
                    $sql .= $comma . $this->field($this->quoteField($f), $cond);
                } else {
                    $sql .= $comma . $v;
                }
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
        if (is_null($str)) {
            return 'NULL';
        }
        if (is_string($str)) {
            // [SQL安全改造] 自增/自减表达式格式已校验，直接嵌入SQL不占位（如 #@#hits+1 → hits+1）
            if (preg_match('/^(#@#){1}[A-Za-z]{2,}([\w])*(\+|\-)([\d.]{1,20})$/i', $str)) {
                return preg_replace('/^#@#/i', '', $str);
            }
            $this->whereParams[] = $str;
            return '?';
        }

        if (is_int($str) or is_float($str)) {
            $this->whereParams[] = $str;
            return '?';
        }

        if (is_array($str)) {
            if ($noarray === false) {
                foreach ($str as &$v) {
                    $v = $this->quote($v, true);
                }
                return $str;
            }
            $this->whereParams[] = '';
            return '?';
        }

        if (is_bool($str)) {
            $this->whereParams[] = $str ? 1 : 0;
            return '?';
        }
        $this->whereParams[] = '';
        return '?';
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

    private function field($field, $val, $glue = '=')
    {
        $field = $this->quoteField($field);
        if (empty($val) && is_array($val)) {
            $val = '';
        }
        if (is_array($val)) {
            // [SQL安全改造] 数组值支持 [glue, value] 惰性条件（如 ['like','xx%']），
            // 参数由 withWhere->implode 按 SQL 字面顺序统一收集，避免预收集被重置或顺序错位
            if (isset($val[0]) && array_key_exists(1, $val) && !is_array($val[1])
                && in_array(strtolower((string)$val[0]), ['like', 'unlike', 'find', 'nofind', 'between', 'regexp', '>', '<', '<>', '>=', '<='], true)) {
                $glue = strtolower((string)$val[0]);
                $val = $val[1];
            } else {
                $glue = $glue == 'notin' ? 'notin' : 'in';
            }
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
                return $field . $glue . $this->quote($val);
            case 'unlike':
            case 'like':
                $not = $glue == 'unlike' ? ' not ' : '';
                if (preg_match('/%/', $val)) {
                    return $field . $not . ' LIKE ' . $this->quote($val); // [SQL安全改造] 使用标准 LIKE ? 语法
                }
                return $field . $not . ' LIKE ' . $this->quote('%' . $val . '%'); // [SQL安全改造] 使用标准 LIKE ? 语法
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
                $min = (int)preg_replace('/[^\d.-]/', '', $min);
                $max = (int)preg_replace('/[^\d.-]/', '', $max);
                return '(' . $field . ' between  ' . $this->quote($min) . ' and ' . $this->quote($max) . ')';
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
            // null 值跳过，让 MySQL 使用列默认值
            // 避免整型 NOT NULL 列收到 '' (1366) 或 NULL (1048)
            if ($v === null) {
                continue;
            }
            if (is_array($v)) {
                $v = json_encode($v);
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
        return $this->db->fetchColumn($sql, 0, $this->whereParams); // [SQL安全改造] 透传绑定参数
    }

    /**
     * 返回分页列表数据
     * @param string $fields
     * @param int $cacheTime
     * @param string $indexField
     * @return array
     */
    public function pageList(string $fields = '*', int $cacheTime = 0, string $indexField = ''): array
    {
        $fields = $fields ?: '*';
        $count = $this->count('*', $cacheTime);
        $maxpages = (int)ceil($count / $this->pageSize);
        $page = $this->page > $maxpages ? $maxpages : $this->page;
        $pageSize = $this->pageSize ?: 30;
        $clone = $this->withLimit($pageSize, $page);
        if (empty($count)) {
            $list = [];
        } else {
            $list = $clone->fetchList($fields, $indexField, $cacheTime);
        }
        return ['list' => $list, 'count' => $count, 'maxpages' => $maxpages, 'page' => $page, 'pagesize' => $pageSize];
    }


    /**
     * 将缓存KEY中含有的时间戳后3位改成000，否则缓存会一直生成，失去缓存意义
     */
    protected function md5key(array $condition = []): string
    {
        $condition[] = $this->where;
        $condition[] = $this->whereParams; // [SQL安全改造] 绑定参数参与缓存KEY，防止不同参数命中同一缓存
        $condition[] = $this->join;
        $condition[] = $this->orderby;
        $condition[] = $this->limit;
        $condition[] = $this->groupby;
        return Str::md5key($condition);
    }
}
