<?php
/**
 * 表单写入服务契约（CUD）
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use SlimCMS\Interfaces\OutputInterface;

/**
 * 表单写入服务
 *
 * 替换原 Forms::dataSave / dataCheck / dataDel / delAttachment / getFormValue / requiredCheck / validCheck
 */
interface FormWriteServiceInterface
{
    /**
     * 保存数据
     *
     * @param int $fid 表单 ID
     * @param int|array|null $row 已有行（int 时按 ID 查询，array 时直接使用）
     * @param array $data 外部传入数据（空时从请求自动提取）
     * @param array $options 透传给钩子
     */
    public function save(int $fid, $row = [], array $data = [], array $options = []): OutputInterface;

    /**
     * 数据审核
     */
    public function check(int $fid, array $ids, int $ischeck = 1, array $options = []): OutputInterface;

    /**
     * 删除数据
     */
    public function delete(int $fid, array $ids, array $options = []): OutputInterface;

    /**
     * 删除附件（按字段类型清理）
     */
    public function deleteAttachments(array $fields, array $data): OutputInterface;

    /**
     * 必填检测
     */
    public function requiredCheck(int $fid, array $row = [], array $data = []): OutputInterface;

    /**
     * 数据有效性检测（供 Repository 调用）
     *
     * @return array 校验通过的数据
     * @throws \SlimCMS\Error\TextException
     */
    public function validCheck(int $fid, array $data, int $id = 0): array;
}
