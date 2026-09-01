<?php
/**
 * 字段规则值对象
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form\DTO;

/**
 * 字段规则（select/radio/checkbox 的可选值映射）
 *
 * 规则来源有两种：
 *  - 字段表中直接存的 JSON rules
 *  - 通过 analysisRules 从其它表查询得到的动态规则
 *
 * 本对象统一封装，避免在业务层到处 json_decode。
 */
final class FieldRules
{
    /**
     * @param array<string,string|array> $map  value => 文本 的映射
     */
    public function __construct(
        private readonly array $map = [],
    ) {}

    public static function fromJson(?string $json): self
    {
        if (empty($json)) {
            return new self();
        }
        $arr = json_decode($json, true);
        return new self(is_array($arr) ? $arr : []);
    }

    public static function fromMap(array $map): self
    {
        return new self($map);
    }

    /**
     * 获取某个值对应的文案
     */
    public function getText(string|int $value): ?string
    {
        return $this->map[(string)$value] ?? null;
    }

    /**
     * 是否包含某个值
     */
    public function contains(string|int $value): bool
    {
        return array_key_exists((string)$value, $this->map);
    }

    /**
     * 是否为单条动态规则（来源其它表），原逻辑 count==1 时触发 tableDataRules
     */
    public function isDynamicSource(): bool
    {
        return count($this->map) === 1;
    }

    public function toArray(): array
    {
        return $this->map;
    }

    public function isEmpty(): bool
    {
        return empty($this->map);
    }
}
