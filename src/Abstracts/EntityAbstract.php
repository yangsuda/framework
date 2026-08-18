<?php
/**
 * Entity 抽象基类 - 通过魔术方法实现动态实体化
 *
 * 核心设计：
 * - 不预先定义属性，通过 __get/__set 魔术方法动态接收数据库字段
 * - 通过 casts 定义字段类型转换规则
 * - 通过 hidden 定义输出时需要隐藏的敏感字段
 * - 完全兼容现有的数据库字段管理方式，无需手动维护字段列表
 *
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use JsonSerializable;

abstract class EntityAbstract implements JsonSerializable
{
    /**
     * 字段类型转换规则
     * 子类定义需要类型转换的字段
     *
     * @example
     * protected array $casts = [
     *     'id' => 'int',
     *     'groupid' => 'int',
     *     'status' => 'bool',
     *     'price' => 'float',
     *     'meta' => 'json',
     *     'createtime' => 'timestamp',
     * ];
     */
    protected array $casts = [
        'id' => 'int',
        'createtime' => 'int',
    ];

    /**
     * 输出时隐藏的字段（敏感字段）
     *
     * @example
     * protected array $hidden = ['pwd', 'token', 'secret'];
     */
    protected array $hidden = [];

    /**
     * 内部存储所有字段值
     */
    private array $attributes = [];

    /**
     * 关联数据缓存
     */
    private array $relations = [];

    /**
     * 魔术方法：读取属性
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        // 优先返回关联数据
        if (isset($this->relations[$name])) {
            return $this->relations[$name];
        }

        // 返回属性值，并进行类型转换
        return $this->castValue($name, $this->attributes[$name] ?? null);
    }

    /**
     * 魔术方法：设置属性
     *
     * @param string $name
     * @param mixed $value
     */
    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * 魔术方法：判断属性是否存在
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || isset($this->relations[$name]);
    }

    /**
     * 魔术方法：删除属性
     *
     * @param string $name
     */
    public function __unset(string $name): void
    {
        unset($this->attributes[$name], $this->relations[$name]);
    }

    /**
     * 从数组创建 Entity 实例
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $instance = new static();

        foreach ($data as $key => $value) {
            // 处理关联数据（以 _ 开头的字段）
            if (str_starts_with($key, '_') && (is_array($value) || $value instanceof EntityAbstract)) {
                $instance->setRelation($key, $value);
            } else {
                $instance->attributes[$key] = $value;
            }
        }

        return $instance;
    }

    /**
     * 从数组列表创建 Entity 列表
     *
     * @param array $list
     * @return array
     */
    public static function fromArrayList(array $list): array
    {
        return array_map(fn($item) => static::fromArray($item), $list);
    }

    /**
     * 设置关联数据
     *
     * @param string $name 关联名称
     * @param mixed $value 关联数据
     */
    public function setRelation(string $name, mixed $value): void
    {
        $this->relations[$name] = is_array($value) ? $value : $value;
    }

    /**
     * 获取关联数据
     *
     * @param string $name
     * @return mixed
     */
    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * 转换为数组（过滤隐藏字段）
     *
     * @return array
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->hidden)) {
                $result[$key] = $this->castValue($key, $value);
            }
        }

        // 添加关联数据
        foreach ($this->relations as $key => $value) {
            if ($value instanceof EntityAbstract) {
                $result[$key] = $value->toArray();
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * JSON 序列化
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * 获取所有原始属性（不过滤、不转换）
     *
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * 获取指定属性的原始值（不进行类型转换）
     *
     * @param string $name
     * @return mixed
     */
    public function getRawAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * 根据 casts 规则进行类型转换
     *
     * @param string $name 字段名
     * @param mixed $value 原始值
     * @return mixed 转换后的值
     */
    protected function castValue(string $name, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $castType = $this->casts[$name] ?? null;

        return match ($castType) {
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'string' => (string)$value,
            'bool', 'boolean' => (bool)$value,
            'array' => is_array($value) ? $value : (array)$value,
            'json' => is_string($value) ? json_decode($value, true) : $value,
            'timestamp' => is_numeric($value) ? (int)$value : strtotime($value),
            'datetime' => is_numeric($value) ? date('Y-m-d H:i:s', (int)$value) : $value,
            'date' => is_numeric($value) ? date('Y-m-d', (int)$value) : $value,
            default => $value,
        };
    }

    /**
     * 判断字段是否存在
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name = null): bool
    {
        return $name ? isset($this->attributes[$name]) : isset($this->attributes);
    }

    /**
     * 批量设置属性
     *
     * @param array $attributes
     * @return static
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    /**
     * 获取所有非隐藏字段名
     *
     * @return array
     */
    public function getVisibleFields(): array
    {
        return array_diff(array_keys($this->attributes), $this->hidden);
    }
}
