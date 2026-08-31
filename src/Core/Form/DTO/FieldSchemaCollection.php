<?php
/**
 * 字段集合（不可变）
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form\DTO;

use Countable;
use IteratorAggregate;
use ArrayIterator;
use Traversable;

/**
 * 不可变字段集合
 *
 * 支持按 identifier 快速查询、按条件过滤。
 * 替换原 Forms 中到处以 array 传递的 $fields。
 */
final class FieldSchemaCollection implements Countable, IteratorAggregate
{
    /** @var array<string,FieldSchema> */
    private array $byIdentifier = [];

    /** @var FieldSchema[] */
    private array $items = [];

    /**
     * @param array<int, array> $rows 数据库行列表
     */
    public function __construct(array $rows = [])
    {
        foreach ($rows as $row) {
            $field = FieldSchema::fromRow($row);
            $this->items[] = $field;
            $this->byIdentifier[$field->identifier] = $field;
        }
    }

    public function get(string $identifier): ?FieldSchema
    {
        return $this->byIdentifier[$identifier] ?? null;
    }

    /**
     * 条件过滤（返回新集合，保持不可变）
     *
     * 支持的过滤键：
     *  - datatype: string|[]，单值或多个之一
     *  - available / inFront / inListCp / isRequired / isUnique / isExport / isSearch / isOrderby: bool
     *  - identifier: []，限定 identifier 集合
     */
    public function filter(array $criteria): self
    {
        $filtered = [];
        foreach ($this->items as $field) {
            if (!$this->matchCriteria($field, $criteria)) {
                continue;
            }
            $filtered[] = $field->raw;
        }
        $new = new self($filtered);
        return $new;
    }

    private function matchCriteria(FieldSchema $field, array $criteria): bool
    {
        foreach ($criteria as $key => $value) {
            switch ($key) {
                case 'datatype':
                    if (is_array($value)) {
                        if (!in_array($field->datatype, $value, true)) {
                            return false;
                        }
                    } elseif ($field->datatype !== (string)$value) {
                        return false;
                    }
                    break;
                case 'available':
                    if ($field->available !== (bool)$value) {
                        return false;
                    }
                    break;
                case 'infront':
                    if ($field->inFront !== (bool)$value) {
                        return false;
                    }
                    break;
                case 'inlistcp':
                    if ($field->inListCp !== (bool)$value) {
                        return false;
                    }
                    break;
                case 'required':
                    if ($field->isRequired !== (bool)$value) {
                        return false;
                    }
                    break;
                case 'unique':
                    if ($field->isUnique !== (bool)$value) {
                        return false;
                    }
                    break;
                case 'isexport':
                    if ($field->isExport !== (bool)$value) {
                        return false;
                    }
                    break;
                case 'search':
                    if ($field->isSearch !== (bool)$value) {
                        return false;
                    }
                    break;
                case 'orderby':
                    if ($field->isOrderby !== (bool)$value) {
                        return false;
                    }
                    break;
                case 'identifier':
                    $idents = is_array($value) ? $value : [$value];
                    if (!in_array($field->identifier, $idents, true)) {
                        return false;
                    }
                    break;
                case 'defaultorder':
                    if (!is_array($value) && (int)$field->defaultOrder !== (int)$value) {
                        return false;
                    } elseif (is_array($value) && !in_array((int)$field->defaultOrder, $value, true)) {
                        return false;
                    }
                    break;
                default:
                    // 兜底：按 raw 字段比较
                    if ($field->rawGet($key) != $value) {
                        return false;
                    }
            }
        }
        return true;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * 转为数组（兼容旧代码 foreach ($fields as $v) 形态）
     */
    public function toRawArray(): array
    {
        return array_map(fn(FieldSchema $f) => $f->raw, $this->items);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }
}
