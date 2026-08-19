<?php
declare(strict_types=1);

namespace SlimCMS\Interfaces;


interface DatabaseInterface
{
    /**
     * 数据库连接
     */
    public function connect();

    /**
     * 返回数据库链接实例
     * @return Dbpdo
     */
    public function getLink();

    /**
     * SQL请求
     * @param string $sql
     * @param array $params 预处理参数（可空）
     */
    public function query(string $sql, $params = []);

    /**
     * 反馈某条数据结果
     * @param string $sql
     * @param array $params 预处理参数（可空）
     * @return array
     */
    public function fetch(string $sql, $params = []);

    /**
     * 查询列表数据
     * @param string $sql
     * @param string $keyfield
     * @param array $params 预处理参数（可空）
     * @return array
     */
    public function fetchList(string $sql, string $keyfield = '', $params = []): array;

    /**
     * 返回查询某字段的值
     * @param string $sql
     * @param int $columnNumber
     * @param array $params 预处理参数（可空）
     * @return string
     */
    public function fetchColumn(string $sql, $columnNumber = 0, $params = []);

    /**
     * 返回插入数据的自增ID
     * @return mixed
     */
    public function insertId();

    /**
     * 返回受上一个 SQL 语句影响的行数
     * @param $query
     * @return int
     */
    public function affectedRows($query): int;
}
