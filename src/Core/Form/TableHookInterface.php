<?php
/**
 * 表生命周期钩子契约
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

/**
 * 表（Table 子类）生命周期钩子契约
 *
 * 替换原 Forms 类中 is_callable([$this->t($table), 'dataXxxBefore']) 的字符串拼装调用。
 * 默认实现全部为"通过"，子类按需覆写。
 *
 * 返回值约定：
 *  - int 200 视为通过
 *  - int 其它非 200 视为拦截，业务层用 withCode($rs) 中止
 *  - array ['code'=>xxx,'msg'=>yyy] 用于带消息中止
 */
interface TableHookInterface
{
    /** 列表查询前参数初始化（&$param 可修改） */
    public function dataListInit(array &$param): int|array;

    /** 列表查询前 */
    public function dataListBefore(array &$param): int|array;

    /** 列表查询后（&$data 可修改） */
    public function dataListAfter(array &$data, array $param): int|array;

    /** 保存前初始化（&$fields、&$data、&$row 可修改） */
    public function dataSaveInit(array &$fields, array &$data, array &$row, array $options): int|array;

    /** 保存前（&$data 可修改） */
    public function dataSaveBefore(array &$data, array $row, array $options): int|array;

    /** 保存后 */
    public function dataSaveAfter(array $data, array $row, array $options): int|array;

    /** 删除前（单行） */
    public function dataDelBefore(array $row, array $options): int|array;

    /** 删除后（单行） */
    public function dataDelAfter(array $row, array $options): int|array;

    /** 删除全部完成后（整批 list） */
    public function dataDelRealAfter(array $list): void;

    /** 审核前 */
    public function dataCheckBefore(array $ids, int $ischeck, array $options): int|array;

    /** 审核后 */
    public function dataCheckAfter(array $ids, int $ischeck, array $options): int|array;

    /** 详情查询前 */
    public function dataViewBefore(int $id, array $options): int|array;

    /** 详情查询后（&$data 可修改） */
    public function dataViewAfter(array &$data, array $options): int|array;

    /** 统计查询前 */
    public function dataCountBefore(array &$param): int|array;

    /** 表单 HTML 生成前（&$fields、&$row 可修改） */
    public function getFormHtmlBefore(array &$fields, array &$row, array $form, array $options): int|array;

    /** 表单 HTML 生成后（&$fieldshtml 可修改） */
    public function getFormHtmlAfter(array &$fieldshtml, array $fields, array $row, array $options): int|array;

    /** 导出前（&$condition 可修改） */
    public function dataExportBefore(array &$condition, $result): int|array;

    /** 导出后 */
    public function dataExportAfter($result): int|array;
}
