<?php
/**
 * 表单视图渲染服务契约
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use SlimCMS\Interfaces\OutputInterface;

/**
 * 表单视图渲染服务
 *
 * 替换原 Forms::dataFormHtml / formHtml / listFields / searchFields / orderFields
 */
interface FormViewRendererInterface
{
    /**
     * 生成表单 HTML
     *
     * @param int $fid 表单 ID
     * @param int|array $row 数据 ID（int）或已有行（array）
     * @param array $options ['cacheTime','ueditorType','infront']
     */
    public function renderFormHtml(int $fid, $row = [], array $options = []): OutputInterface;

    /**
     * 后台列表展示字段
     */
    public function listFields(int $fid, int $limit = 30, string $fieldName = 'inlistcp'): OutputInterface;

    /**
     * 参与搜索字段（含 HTML）
     */
    public function searchFields(int $fid, string $fields = ''): OutputInterface;

    /**
     * 参与排序字段
     */
    public function orderFields(int $fid): OutputInterface;

    /**
     * 所有可用字段
     */
    public function allValidFields(int $fid): OutputInterface;
}
