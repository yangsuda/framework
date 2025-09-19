<?php
/**
 * redis类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Core;

use Psr\Container\ContainerInterface;
use SlimCMS\Error\TextException;

class Redis
{
    public static $redis;
    protected $setting;

    public function __construct(ContainerInterface $container)
    {
        if (empty(self::$redis)) {
            $this->setting = $container->get('settings');
            $config = &$this->setting['redis'];
            if (!empty($config['server'])) {
                try {
                    $redis = new \Redis();
                    if (!empty($config['pconnect'])) {
                        $connect = @$redis->pconnect($config['server'], aval($config, 'port'));
                    } else {
                        $connect = @$redis->connect($config['server'], aval($config, 'port'));
                    }
                    if ($connect) {
                        !empty($config['password']) && $redis->auth($config['password']);
                        //0 值不序列化保存，1反之(序列化可以存储对象)
                        $redis->setOption(\Redis::OPT_SERIALIZER, 0);
                        $dbindex = !empty($config['dbindex']) ? $config['dbindex'] : 1;
                        $redis->select($dbindex);
                        self::$redis = &$redis;
                    }
                } catch (RedisException $e) {
                    throw new TextException(21057, $e->getMessage(), 'redis');
                }
            }
        }
    }

    /**
     * 设置存储库
     * @param int $dbindex
     * @return $this|null
     */
    public function selectDB(int $dbindex = 1)
    {
        if (empty(self::$redis)) {
            return $this;
        }
        self::$redis->select($dbindex);
        return $this;
    }

    /**
     * 是否可用
     * @return bool
     */
    public function isAvailable()
    {
        return !empty(self::$redis);
    }

    protected function cacheKey(&$key)
    {
        $key = $this->setting['redis']['prefix'] . $key;
    }

    /**
     * 返回redis信息
     * @return string|null
     */
    public function info()
    {
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->info();
    }

    public function get($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        $data = self::$redis->get($key);
        if (is_numeric($data)) {
            return $data;
        }
        return $data ? unserialize($data) : $data;
    }

    public function set($key, $data, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        if (!empty($data)) {
            if (!is_numeric($data)) {
                $data = serialize($data);
            }
            self::$redis->set($key, $data, $ttl);
            return true;
        }
        return $this->del($key);
    }

    /**
     * 指定的 key 不存在时，才为 key 设置指定的值
     * @param unknown_type $key
     * @param unknown_type $data
     * @param unknown_type $ttl
     */
    public function setnx($key, $data, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        if (!empty($data)) {
            if (!is_numeric($data)) {
                $data = serialize($data);
            }
            $res = self::$redis->setnx($key, $data);
            if ($res && $ttl) {
                self::$redis->expire($key, $ttl);
            }
            return $res;
        }
        return $this->del($key);
    }

    /**
     * 数值递增
     * @param $key
     * @param int $ttl
     * @return int|null
     */
    public function incr($key, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        $res = self::$redis->incr($key);
        if ($ttl) {
            self::$redis->expire($key, $ttl);
        }
        return $res;
    }

    /**
     * 数值递减
     * @param $key
     * @param int $ttl
     * @return int|null
     */
    public function decr($key, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        $res = self::$redis->decr($key);
        if ($ttl) {
            self::$redis->expire($key, $ttl);
        }
        return $res;
    }

    /**
     * 所储存的值减去指定的减量值
     * @param $key
     * @param int $num
     * @param int $ttl
     * @return int|null
     */
    public function decrby($key, $num = 1, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        $res = self::$redis->decrBy($key, $num);
        if ($ttl) {
            self::$redis->expire($key, $ttl);
        }
        return $res;
    }

    /**
     * 将 key 中储存的数字加上指定的增量值
     * @param $key
     * @param int $num
     * @param int $ttl
     * @return int|null
     */
    public function incrby($key, $num = 1, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        $res = self::$redis->incrby($key, $num);
        if ($ttl) {
            self::$redis->expire($key, $ttl);
        }
        return $res;
    }

    /**
     * 检查给定 key 是否存在
     * @param $key
     * @return int|null
     */
    public function exists($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->exists($key);
    }

    /**
     * 设置 key 的过期时间
     * @param $key
     * @param $ttl
     * @return bool|null
     */
    public function expire($key, $ttl)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->expire($key, $ttl);
    }

    public function del($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->del($key);
    }

    /**
     * 用于同时将多个 field-value (字段-值)对设置到哈希表中。此命令会覆盖哈希表中已存在的字段。
     * @param $key
     * @param $data
     * @param int $ttl
     * @return bool|null
     */
    public function hmset($key, $data, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        self::$redis->hmset($key, $data);
        if ($ttl) {
            self::$redis->expire($key, $ttl);
        }
        return true;
    }

    /**
     * 返回哈希表中，一个或多个给定字段的值。 如果指定的字段不存在于哈希表，那么返回一个 nil 值
     * @param $key
     * @param $hashKeys
     * @return array|null
     */
    public function hmget($key, $hashKeys)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->hMGet($key, $hashKeys);
    }

    /**
     * 为哈希表中的字段赋值 。 如果哈希表不存在，一个新的哈希表被创建并进行 HSET 操作
     * @param $key
     * @param $field
     * @param $data
     * @param int $ttl
     * @return bool|null
     */
    public function hset($key, $field, $data, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        self::$redis->hset($key, $field, $data);
        if ($ttl) {
            self::$redis->expire($key, $ttl);
        }
        return true;
    }

    /**
     * 为哈希表中不存在的的字段赋值 。如果表不存在，一个新的表被创建并进行 HSET 操作。如果字段已经存在于表中，操作无效。
     * @param $key
     * @param $field
     * @param $data
     * @param int $ttl
     * @return bool|null
     */
    public function hsetnx($key, $field, $data, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        $res = self::$redis->hsetnx($key, $field, $data);
        if ($res && $ttl) {
            self::$redis->expire($key, $ttl);
        }
        return $res;
    }

    /**
     * 返回哈希表中指定字段的值
     * @param $key
     * @param $field
     * @return string|null
     */
    public function hget($key, $field)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->hget($key, $field);
    }

    /**
     * 命令用于为哈希表中的字段值加上指定增量值。 增量也可以为负数，相当于对指定字段进行减法操作。
     * @param $key
     * @param $field
     * @param int $num
     * @param int $ttl
     * @return int|null
     */
    public function hincrby($key, $field, $num = 1, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        $res = self::$redis->hincrby($key, $field, $num);
        if ($ttl) {
            self::$redis->expire($key, $ttl);
        }
        return $res;
    }

    /**
     * 查看哈希表的指定字段是否存在
     * @param $key
     * @param $field
     * @return bool|null
     */
    public function hexists($key, $field)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->hexists($key, $field);
    }

    /**
     * 用于获取哈希表中字段的数量
     * @param $key
     * @return int|null
     */
    public function hlen($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->hlen($key);
    }

    /**
     * 用于返回哈希表中，所有的字段和值
     * @param $key
     * @return array|null
     */
    public function hgetall($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->hgetall($key);
    }

    /**
     * 用于删除哈希表 key 中的一个或多个指定字段
     * @param $key
     * @param $fields
     * @return null
     */
    public function hdel($key, $fields)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        if (is_array($fields)) {
            // 使用可变参数传递数组
            $res = self::$redis->hdel($key, ...array_values($fields));
        } else {
            $res = self::$redis->hdel($key, $fields);
        }
        return $res;
    }

    /**
     * 将一个或多个成员元素及其分数值加入到有序集当中
     * @param $key
     * @param $score
     * @param $member
     * @param int $ttl
     * @return bool|null
     */
    public function zadd($key, $score, $member, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        self::$redis->zadd($key, $score, $member);
        if ($ttl) {
            self::$redis->expire($key, $ttl);
        }
        return true;
    }

    /**
     * 有序集合中对指定成员的分数加上增量 increment
     * @param $key
     * @param $value
     * @param $member
     * @param int $ttl
     * @return bool
     */
    public function zincrby($key, $value, $member, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        self::$redis->zIncrBy($key, $value, $member);
        if ($ttl) {
            self::$redis->expire($key, $ttl);
        }
        return true;
    }

    /**
     * 移除有序集中的一个或多个成员
     * @param $key
     * @param $members
     * @return null
     */
    public function zrem($key, $members)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        if (is_array($members)) {
            // 使用可变参数传递数组
            $res = self::$redis->zrem($key, ...array_values($members));
        } else {
            $res = self::$redis->zrem($key, $members);
        }
        return $res;
    }

    /**
     * 返回有序集合中指定分数区间的成员列表。有序集成员按分数值递增(从小到大)次序排列
     * @param $key
     * @param $start
     * @param $end
     * @return null
     */
    public function zrangebyscore($key, $start, $end)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->zrangebyscore($key, (string)$start, (string)$end);
    }

    /**
     * 返回有序集中指定分数区间内的成员，分数从高到低排序
     * @param $key
     * @param $start
     * @param $end
     * @return null
     */
    public function zrevrangebyscore($key, $start, $end)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->zrevrangebyscore($key, (string)$start, (string)$end);
    }

    /**
     * 移除有序集合中给定的分数区间的所有成员
     * @param $key
     * @param $start
     * @param $end
     * @return int
     */
    public function zremrangebyscore($key, $start, $end)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->zRemRangeByScore($key, (string)$start, (string)$end);
    }

    /**
     * 获取有序集合的成员数
     * @param $key
     * @return int
     */
    public function zcard($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->zCard($key);
    }

    /**
     * 返回有序集中，指定区间内的成员。其中成员的位置按分数值递增(从小到大)来排序
     * @param $key
     * @param $start
     * @param $end
     * @param null $withscores
     * @return array|null
     */
    public function zrange($key, $start, $end, $withscores = null)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->zRange($key, $start, $end, $withscores);
    }

    /**
     * 读取指定范围从大小到的数据
     * @param $key
     * @param $start
     * @param $end
     * @param null $withscores true返回分数值
     * @return array
     */
    public function zrevrange($key, $start, $end, $withscores = null)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->zRevRange($key, $start, $end, $withscores);
    }

    /**
     * 返回有序集合中指定成员的排名，有序集成员按分数值递减(从大到小)排序
     * @param $key
     * @param $member
     * @return int
     */
    public function zrevRank($key, $member)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->zRevRank($key, $member);
    }

    /**
     * 返回有序集合中指定成员的索引
     * @param $key
     * @param $member
     * @return int
     */
    public function zrank($key, $member)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->zrank($key, $member);
    }

    /**
     * 返回有序集中，成员的分数值
     * @param $key
     * @param $member
     * @return float
     */
    public function zscore($key, $member)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->zScore($key, $member);
    }

    /**
     * 计算在有序集合中指定区间分数的成员数
     * @param $key
     * @param $start
     * @param $end
     * @return int
     */
    public function zcount($key, $start, $end)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->zCount($key, $start, $end);
    }

    /**
     * 有序集合取交集
     * @param string $key
     * @param array $members
     * @return mixed|null
     */
    public function zInterStore(string $key, array $members)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        array_walk($members, array($this, "cacheKey"));
        foreach ($members as $v) {
            $this->cacheKey($key);
        }
        $res = self::$redis->zInterStore($key, $members);
        // 获取交集结果
        return self::$redis->zRange($key, 0, -1, true);
    }

    /**
     * 有序集合取并集
     * @param string $key
     * @param array $members
     * @return null
     */
    public function zUnionStore(string $key, array $members)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        array_walk($members, array($this, "cacheKey"));
        $res = self::$redis->zUnionStore($key, $members);
        // 获取交集结果
        return self::$redis->zRange($key, 0, -1, true);
    }

    /**
     * 返回 key 的剩余过期时间
     * @param $key
     * @return int|null
     */
    public function ttl($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->ttl($key);
    }

    /**
     * 向集合添加一个或多个成员
     * @param $key
     * @param $member
     * @param int $ttl
     * @return bool
     */
    public function sadd($key, $member, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        if (is_array($member)) {
            //一次写太多程序要挂掉，只能分段写
            foreach ($this->yield_slice($member) as $slice) {
                if (empty($slice)) {
                    break;
                }
                // 使用可变参数传递数组
                $res = self::$redis->sadd($key, ...array_values($slice));
            }
            unset($member);
        } else {
            self::$redis->sadd($key, $member);
        }
        if ($ttl) {
            self::$redis->expire($key, $ttl);
        }
        return true;
    }

    /**
     * 移除集合中的一个或多个成员元素，不存在的成员元素会被忽略
     * @param $key
     * @param $member
     * @return bool|null
     */
    public function srem($key, $member)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        if (is_array($member)) {
            //一次写太多程序要挂掉，只能分段写
            foreach ($this->yield_slice($member) as $slice) {
                if (empty($slice)) {
                    break;
                }
                // 使用可变参数传递数组
                $res = self::$redis->srem($key, ...array_values($slice));
            }
            unset($member);
        } else {
            self::$redis->srem($key, $member);
        }
        return true;
    }

    /**
     * 大数据通过协程处理
     */
    protected function yield_slice($data)
    {
        for ($i = 0; $i < 100; $i++) {
            $start = $i * 50000;
            yield $slice = array_slice($data, $start, 50000);
        }
    }

    /**
     * 获取集合的成员数
     * @param $key
     * @return int
     */
    public function scard($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->scard($key);
    }

    /**
     * 返回集合中的所有成员
     * @param $key
     * @return array
     */
    public function smembers($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->sMembers($key);
    }

    /**
     * 判断 member 元素是否是集合 key 的成员
     * @param $key
     * @param $val
     * @return bool
     */
    public function sismember($key, $val)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->sismember($key, $val);
    }

    /**
     * 将一个或多个值插入到列表头部
     * @param $key
     * @param $value
     * @param int $ttl
     */
    public function lpush($key, $value, $ttl = 5184000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        if (is_array($value)) {
            // 使用可变参数传递数组
            $res = self::$redis->lpush($key, ...array_values($value));
        } else {
            $res = self::$redis->lpush($key, $value);
        }
        if ($ttl && $res !== false) {
            self::$redis->expire($key, $ttl);
        }
        return $res;
    }

    /**
     * 取出列表集中指定长度数据
     * @param $key
     * @param int $stat
     * @param int $end
     * @return array
     */
    public function lrange($key, $stat = 0, $end = -1)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->lrange($key, $stat, $end);
    }

    /**
     * 向列表末尾插入元素
     * @param $key
     * @param $value
     * @param int $ttl
     */
    public function rpush($key, $value, $ttl = 5184000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        if (is_array($value)) {
            // 使用可变参数传递数组
            $res = self::$redis->rpush($key, ...array_values($value));
        } else {
            $res = self::$redis->rpush($key, $value);
        }
        if ($ttl && $res !== false) {
            self::$redis->expire($key, $ttl);
        }
        return $res;
    }

    /**
     * 取出列表中的元素
     * @param $key
     * @param int $index
     * @return mixed
     */
    public function lindex($key, $index = 0)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->lindex($key, $index);
    }

    /**
     * 获取列表长度
     * @param $key
     * @return int
     */
    public function llen($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->llen($key);
    }

    /**
     * 去除列表的第一个元素
     * @param $key
     * @return string
     */
    public function lpop($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->lpop($key);
    }

    /**
     * 去除列表的某个元素
     * @param $key
     * @param $value
     * @param $count
     * @return int
     */
    public function lrem($key, $value, $count = 0)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->lrem($key, $value, $count);
    }

    /**
     *  对一个列表进行修剪，让列表只保留指定区间内的元素，不在指定区间之内的元素都将被删除
     * @param $key
     * @param int $stat
     * @param string $end
     * @return array
     */
    public function ltrim($key, $stat = 0, $end = '-1')
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->ltrim($key, $stat, $end);
    }

    /**
     * 查找符合给定模式的key
     * @param $key
     * @return array
     */
    public function keys($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->keys($key);
    }

    /**
     * 批量删除相关key
     * @param $key
     * @return bool
     */
    public function delKeys($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        if ($key == '*') {
            return false;
        }
        $res = self::$redis->keys($key);
        foreach ($res as $v) {
            self::$redis->del($v);
        }
        return true;
    }

    /**
     * 用scan达到keys的效果
     * @param $pattern
     * @return array
     */
    public function scan($pattern)
    {
        if (empty(self::$redis)) {
            return null;
        }
        self::$redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
        $iterator = null;
        $key_array = array();
        while ($keys = self::$redis->scan($iterator, $pattern)) {
            $key_array = array_merge($key_array, $keys);
        }
        return $key_array;
    }

    /**
     * 用hscan达到hgetall的效果
     * @param $key
     * @return array
     */
    public function hscan($key)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        self::$redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
        $iterator = null;
        $key_array = array();
        while ($keys = self::$redis->hScan($key, $iterator)) {
            $key_array += $keys;
        }
        return $key_array;
    }

    /**
     * 随机返回set中的item
     * @param $key
     * @param null $count
     * @return array|string
     */
    public function sRandMember($key, $count = null)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->sRandMember($key, $count);
    }

    /**
     * 清空所有的key，必须谨慎操作
     * @return bool|null
     */
    public function flushall()
    {
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->flushall();
    }


    /**
     * 添加元素信息
     * @param string $key 键名
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @param string $member 元素名称
     * @param int $ttl 有效期，秒
     * @return int 当前元素数量
     */
    public function geoAdd($key, $longitude, $latitude, $member, $ttl = 12960000)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        $res = self::$redis->geoadd($key, $longitude, $latitude, $member);
        if ($res && $ttl) {
            self::$redis->expire($key, $ttl);
        }
        return $res;
    }

    /**
     * 返回两点之间的距离
     * @param string $key 键名
     * @param string $member1 元素1
     * @param string $member2 元素2
     * @param null $unit 距离单位。m 表示单位为米。km 表示单位为千米。mi 表示单位为英里。ft 表示单位为英尺。
     * @return float 距离
     */
    public function geoDist($key, $member1, $member2, $unit)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->geodist($key, $member1, $member2, $unit);
    }

    /**
     * 返回元素的经纬度
     * @param string $key 键名
     * @param string $member 元素
     * @return array 经纬度值数组
     */
    public function geoPos($key, $member)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->geopos($key, $member);
    }

    /**
     * 以一个位置画圆，返回指定距离内所有的元素
     * @param string $key 键名
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @param float $radius 距离
     * @param string $unit 距离单位，m 表示单位为米。km 表示单位为千米。mi 表示单位为英里。ft 表示单位为英尺
     * @param array $options 额外信息数组
     * |Key         |Value          |Description                                 |
     * |------------|---------------|------------------------------------------- |
     * |COUNT       |integer > 0    |返回元素个数                                  |
     * |            |WITHCOORD      |将位置元素的经度和维度一并返回                   |
     * |            |WITHDIST       |将位置元素与中心之间的距离一并返回                |
     * |            |WITHHASH       |返回位置元素经过原始 geohash 编码的有序集合分值    |
     * |            |ASC            |按照从近到远的方式返回位置元素                   |
     * |            |DESC           |按照从远到近的方式返回位置元素                   |
     * |STORE       |key            |将返回结果的地理位置信息保存到指定键              |
     * |STOREDIST   |key            |将返回结果距离中心节点的距离保存到指定键           |
     *
     * @return mixed
     */
    public function geoRadius($key, $longitude, $latitude, $radius, $unit, $options)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->georadius($key, $longitude, $latitude, $radius, $unit, $options);
    }

    /**
     * 找出位于指定范围内的元素，中心点是由给定的位置元素决定的。
     * @param string $key 键名
     * @param string $member 元素
     * @param float $radius 距离
     * @param string $units 距离单位，m 表示单位为米。km 表示单位为千米。mi 表示单位为英里。ft 表示单位为英尺
     * @param array $options 额外信息数组
     * |Key         |Value          |Description                                 |
     * |------------|---------------|------------------------------------------- |
     * |COUNT       |integer > 0    |返回元素个数                                  |
     * |            |WITHCOORD      |将位置元素的经度和维度一并返回                   |
     * |            |WITHDIST       |将位置元素与中心之间的距离一并返回                |
     * |            |WITHHASH       |返回位置元素经过原始 geohash 编码的有序集合分值    |
     * |            |ASC            |按照从近到远的方式返回位置元素                   |
     * |            |DESC           |按照从远到近的方式返回位置元素                   |
     * |STORE       |key            |将返回结果的地理位置信息保存到指定键              |
     * |STOREDIST   |key            |将返回结果距离中心节点的距离保存到指定键           |
     * @return array
     */
    public function geoRadiusByMember($key, $member, $radius, $units, $options)
    {
        $this->cacheKey($key);
        if (empty(self::$redis)) {
            return null;
        }
        return self::$redis->georadiusbymember($key, $member, $radius, $units, $options);
    }

    /**
     * 消息发布
     * @param $value
     * @param int $ttl
     * @return bool
     */
    public function MQPublish($value, $ttl = 864000)
    {
        $queueKey = 'messageQueue';
        $this->cacheKey($queueKey);
        $value = serialize($value);
        $this->rpush($queueKey, $value, $ttl);
        return true;
    }

    /**
     * 消息消费
     * @param $callback
     * @return bool
     */
    public function MQConsume($callback)
    {
        $queueKey = 'messageQueue';
        $this->cacheKey($queueKey);
        $data = $this->lpop($queueKey);
        if (!empty($data)) {
            $data = unserialize($data);
            return $callback($data);
        }
        return false;
    }
}