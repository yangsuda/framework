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
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET character_set_connection=' . aval($db, 'dbcharset') .
                    ', character_set_results=' . aval($db, 'dbcharset') . ', character_set_client=binary, sql_mode=\'\'';
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
            $msg = $e->getMessage() . " " . $sql;
            throw new TextException(21055, $msg, 'pdo');
        }
        return $query;
    }

    /**
     * 将参数绑定到SQL中，生成真实SQL
     */
    private function interpolateQuery(string $sql, array $params = [])
    {
        $keys = array();
        $values = $params;

        // 处理命名参数 :name 或 ? 占位符
        foreach ($params as $key => $value) {
            if (is_string($key)) {
                $keys[] = '/:' . $key . '/';
            } else {
                $keys[] = '/\?/';
            }

            // 转义字符串
            if (is_string($value)) {
                $values[$key] = "'" . addslashes($value) . "'";
            } elseif (is_null($value)) {
                $values[$key] = 'NULL';
            } elseif (is_bool($value)) {
                $values[$key] = $value ? '1' : '0';
            }
        }

        // 替换占位符
        if (strpos($sql, ':') !== false) {
            // 命名参数
            $realSql = preg_replace($keys, $values, $sql, 1);
        } else {
            // ? 占位符
            $realSql = preg_replace($keys, $values, $sql, 1);
        }

        return $realSql;
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
