<?php
/**
 * 字段元数据值对象（不可变）
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form\DTO;

/**
 * 单个字段的不可变描述
 *
 * 替换原 Forms 中到处传递的 $v['identifier']/['datatype']/['rules'] 数组。
 * rules 在构造时一次性解析为 FieldRules 对象，避免重复 json_decode。
 */
final class FieldSchema
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $datatype,
        public readonly string $title,
        public readonly bool $available,
        public readonly bool $inFront,
        public readonly bool $inListCp,
        public readonly bool $isRequired,
        public readonly bool $isUnique,
        public readonly bool $isExport,
        public readonly bool $isSearch,
        public readonly bool $isOrderby,
        public readonly bool $preciseSearch,
        public readonly bool $forbidEdit,
        public readonly ?FieldRules $rules,
        public readonly string $units,
        public readonly string $egroup,
        public readonly string $default,
        public readonly int $defaultOrder,
        public readonly int $displayOrder,
        public readonly int $maxLength,
        public readonly string $checkRule,
        public readonly string $errorMsg,
        public readonly string $nullMsg,
        public readonly string $tip,
        public readonly string $intro,
        /** 原始行，用于兼容渲染层对未声明字段的访问 */
        public readonly array $raw,
    ) {}

    /**
     * 是否多值类型（用于 searchCondition / getFormValue 分派）
     */
    public function isMultiple(): bool
    {
        return in_array($this->datatype, ['checkbox', 'imgs', 'addons', 'multidate'], true);
    }

    /**
     * 是否需要从请求中读取（getFormValue 用）
     */
    public function isRequestable(): bool
    {
        return $this->inFront === true;
    }

    /**
     * 从数据库行构造，自动解析 rules
     */
    public static function fromRow(array $row): self
    {
        $rulesJson = (string)($row['rules'] ?? '');
        $rules = FieldRules::fromJson($rulesJson);

        return new self(
            identifier: (string)($row['identifier'] ?? ''),
            datatype: (string)($row['datatype'] ?? ''),
            title: (string)($row['title'] ?? ''),
            available: ((int)($row['available'] ?? 0)) === 1,
            inFront: ((int)($row['infront'] ?? 0)) === 1,
            inListCp: ((int)($row['inlistcp'] ?? 0)) === 1,
            isRequired: ((int)($row['required'] ?? 0)) === 1,
            isUnique: ((int)($row['unique'] ?? 0)) === 1,
            isExport: ((int)($row['isexport'] ?? 0)) === 1,
            isSearch: ((int)($row['search'] ?? 0)) === 1,
            isOrderby: ((int)($row['orderby'] ?? 0)) === 1,
            preciseSearch: ((int)($row['precisesearch'] ?? 0)) === 1,
            forbidEdit: ((int)($row['forbidedit'] ?? 0)) === 2,
            rules: $rules,
            units: (string)($row['units'] ?? ''),
            egroup: (string)($row['egroup'] ?? ''),
            default: (string)($row['default'] ?? ''),
            defaultOrder: (int)($row['defaultorder'] ?? 0),
            displayOrder: (int)($row['displayorder'] ?? 0),
            maxLength: (int)($row['maxlength'] ?? 0),
            checkRule: (string)($row['checkrule'] ?? ''),
            errorMsg: (string)($row['errormsg'] ?? ''),
            nullMsg: (string)($row['nullmsg'] ?? ''),
            tip: (string)($row['tip'] ?? ''),
            intro: (string)($row['intro'] ?? ''),
            raw: $row,
        );
    }

    /**
     * 取原始字段值（兼容旧代码 aval($v,'xxx')）
     */
    public function rawGet(string $key, $default = null)
    {
        return $this->raw[$key] ?? $default;
    }
}
