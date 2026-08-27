<?php

/**
 * 数据库操作类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Core;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Container\ContainerInterface;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\File;
use SlimCMS\Interfaces\DatabaseInterface;

class Database implements DatabaseInterface
{
    protected $setting;
    public $link;

    public function __construct(ContainerInterface $container)
    {
        $this->setting = $container->get('settings');
        $this->link = $this->connect();
        $error = $this->link->errorInfo();
        if (in_array($error[1], [2006, 2013])) {
            $this->link = $this->connect();
        }
        $this->link->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    /**
     * {@inheritDoc}
     */
    public function connect()
    {
        if (empty($this->link)) {
            try {
                $db = &$this->setting['db'];
                $options = aval($db, 'pconnect') ? [\PDO::ATTR_PERSISTENT => true] : [];
                $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION; // [SQL安全改造] 开启异常模式，统一错误处理
                $sqlMode = aval($db, 'sql_mode') ?: 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET character_set_connection=' . aval($db, 'dbcharset') .
                    ', character_set_results=' . aval($db, 'dbcharset') . ', character_set_client=binary, sql_mode=\'' . $sqlMode . '\'';
                $connecttype = aval($db, 'connecttype') == ':' ? ':' : ';port=';
                $dsn = 'mysql:host=' . aval($db, 'dbhost') . $connecttype . aval($db, 'dbport') . ';dbname=' . aval($db, 'dbname');
                return new PDO($dsn, aval($db, 'dbuser'), aval($db, 'dbpw'), $options);
            } catch (PDOException $e) {
                throw new TextException(21054, $e->getMessage(), 'pdo');
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getLink(): PDO
    {
        return $this->link;
    }

    /**
     * {@inheritDoc}
     */
    public function insertId()
    {
        return $this->link->lastInsertId();
    }

    /**
     * {@inheritDoc}
     */
    public function query($sql, $params = []): PDOStatement
    {
        try {
            if ($params) {
                // [SQL安全改造] 有参数时走预处理，杜绝SQL注入
                $query = $this->link->prepare($sql);
                $query->execute($params);
            } else {
                $query = $this->link->query($sql);
            }
            if (defined('CORE_DEBUG') && CORE_DEBUG === true) {
                $realSql = $this->interpolateQuery($sql, $params);
                File::log('SQL')->info($realSql);
            }
        } catch (PDOException $e) {
            throw new TextException(21055, $e->getMessage(), 'pdo');
        }
        return $query;
    }

    /**
     * 将参数绑定到SQL中，生成真实SQL
     */
    private function interpolateQuery(string $sql, array $params = [])
    {
        if (empty($params)) {
            return $sql;
        }

        // 命名参数 :name：用 strtr 整体替换，规避正则元字符与 limit 截断问题
        if (is_string(key($params))) {
            $replaced = [];
            foreach ($params as $key => $value) {
                $replaced[':' . $key] = $this->quoteForLog($value);
            }
            return strtr($sql, $replaced);
        }

        // ? 占位符：按出现顺序逐个替换
        $segments = explode('?', $sql);
        if (count($segments) - 1 !== count($params)) {
            return $sql;
        }
        $realSql = $segments[0];
        for ($i = 0, $n = count($params); $i < $n; $i++) {
            $realSql .= $this->quoteForLog($params[$i]) . $segments[$i + 1];
        }
        return $realSql;
    }

    /**
     * 为调试日志生成字面值：走 PDO 驱动层 quote() 转义，与连接字符集一致，避免 addslashes 多字节绕过
     */
    private function quoteForLog($value): string
    {
        if (is_null($value)) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        $quoted = $this->link->quote((string)$value);
        return $quoted === false ? "'" . addslashes((string)$value) . "'" : $quoted;
    }

    /**
     * {@inheritDoc}
     */
    public function fetch(string $sql, $params = [])
    {
        $query = $this->query($sql, $params);
        $data = $query->fetch(PDO::FETCH_ASSOC);
        $query->closeCursor();
        return $data ?? [];
    }

    /**
     * {@inheritDoc}
     */
    public function fetchList(string $sql, string $keyfield = '', $params = []): array
    {
        $data = [];
        $query = $this->query($sql, $params);
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            if ($keyfield && isset($row[$keyfield])) {
                $data[$row[$keyfield]] = $row;
            } else {
                $data[] = $row;
            }
        }
        $query->closeCursor();
        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchColumn(string $sql, $columnNumber = 0, $params = [])
    {
        $query = $this->query($sql, $params);
        $data = $query->fetchColumn($columnNumber);
        $query->closeCursor();
        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function affectedRows($query): int
    {
        $data = $query->rowCount();
        $query->closeCursor();
        return $data;
    }
}