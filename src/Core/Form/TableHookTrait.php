<?php
/**
 * 表生命周期钩子默认实现（trait）
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

/**
 * 钩子默认实现（全部通过）
 *
 * 用途：
 *  - 让 Table 基类 use 本 trait，即可 implements TableHookInterface 而无需重复写空方法
 *  - NullTableHook 也 use 本 trait，作为分发器兜底对象
 *  - 子类按需覆写具体钩子方法
 */
trait TableHookTrait
{
    public function dataListInit(array &$param): int|array
    {
        return 200;
    }

    public function dataListBefore(array &$param): int|array
    {
        return 200;
    }

    public function dataListAfter(array &$data, array $param): int|array
    {
        return 200;
    }

    public function dataSaveInit(array &$fields, array &$data, array &$row, array $options): int|array
    {
        return 200;
    }

    public function dataSaveBefore(array &$data, array $row, array $options): int|array
    {
        return 200;
    }

    public function dataSaveAfter(array $data, array $row, array $options): int|array
    {
        return 200;
    }

    public function dataDelBefore(array $row, array $options): int|array
    {
        return 200;
    }

    public function dataDelAfter(array $row, array $options): int|array
    {
        return 200;
    }

    public function dataDelRealAfter(array $list): void
    {
        // no-op
    }

    public function dataCheckBefore(array $ids, int $ischeck, array $options): int|array
    {
        return 200;
    }

    public function dataCheckAfter(array $ids, int $ischeck, array $options): int|array
    {
        return 200;
    }

    public function dataViewBefore(int $id, array $options): int|array
    {
        return 200;
    }

    public function dataViewAfter(array &$data, array $options): int|array
    {
        return 200;
    }

    public function dataCountBefore(array &$param): int|array
    {
        return 200;
    }

    public function getFormHtmlBefore(array &$fields, array &$row, array $form, array $options): int|array
    {
        return 200;
    }

    public function getFormHtmlAfter(array &$fieldshtml, array $fields, array $row, array $options): int|array
    {
        return 200;
    }

    public function dataExportBefore(array &$condition, $result): int|array
    {
        return 200;
    }

    public function dataExportAfter($result): int|array
    {
        return 200;
    }
}
