<?php
/**
 * 表单元数据值对象（不可变）
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form\DTO;

/**
 * 不可变表单元数据
 *
 * 替换原 Forms::formView() 返回的数组形态，消除 aval() 反复查询。
 * 属性 readonly 保证进程内不会被意外篡改。
 */
final class FormDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $table,
        public readonly string $name,
        public readonly bool $isArchive,
        public readonly bool $cpCheck,
        /** 原始行数据，兼容旧代码aval($form,'xxx')的读取习惯 */
        public readonly array $raw,
    ) {}

    /**
     * 从数据库行构造
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int)($row['id'] ?? 0),
            table: (string)($row['table'] ?? ''),
            name: (string)($row['name'] ?? ''),
            isArchive: ((int)($row['isarchive'] ?? 0)) === 1,
            cpCheck: ((int)($row['cpcheck'] ?? 0)) === 1,
            raw: $row,
        );
    }
}
