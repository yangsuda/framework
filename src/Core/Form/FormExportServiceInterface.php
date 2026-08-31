<?php
/**
 * 表单导出服务契约
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use SlimCMS\Interfaces\OutputInterface;

/**
 * 表单导出服务
 *
 * 替换原 Forms::dataExport / exportData
 * 拆分为"生成内容"和"流式下载"两步，避免 OOM。
 */
interface FormExportServiceInterface
{
    /**
     * 导出（生成 xls 文件并返回下载进度）
     *
     * @param array $param ['fid','pagesize', ...] 透传给 FormQueryService::list()
     */
    public function export(array $param): OutputInterface;

    /**
     * 流式下载已生成的文件
     *
     * @param string $filepath 由 export() 返回的 file 字段
     */
    public function streamDownload(string $filepath): OutputInterface;
}
