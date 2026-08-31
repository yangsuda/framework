<?php
/**
 * 表单元数据只读服务契约
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use SlimCMS\Core\Form\DTO\FormDTO;
use SlimCMS\Core\Form\DTO\FieldSchemaCollection;

/**
 * 表单元数据只读服务
 *
 * 替换原 Forms::formView() / fieldList() / formFields()
 * 进程内 static + Redis 双层缓存，杜绝 N+1。
 */
interface FormSchemaServiceInterface
{
    /**
     * 取表单元数据（含原始行）
     *
     * @throws \SlimCMS\Error\TextException 当表单不存在
     */
    public function getForm(int $fid): FormDTO;

    /**
     * 取字段列表（可过滤）
     *
     * 支持的 criteria 见 FieldSchemaCollection::filter
     */
    public function getFields(int $fid, array $criteria = [], int $limit = 200): FieldSchemaCollection;

    /**
     * 取所有可用字段
     */
    public function allValidFields(int $fid): FieldSchemaCollection;

    /**
     * 解析"动态规则"——单条 rules 从其它表查询的映射
     *
     * 替换原 Forms::tableDataRules()
     */
    public function resolveDynamicRules(array $rules): array;

    /**
     * 解析 analysisRules——从 rules JSON 反查表/字段配置
     */
    public function analysisRules(array $rules): array;
}
