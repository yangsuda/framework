<?php
/**
 * 表单查询服务契约（R）
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use SlimCMS\Interfaces\OutputInterface;

/**
 * 表单查询服务
 *
 * 替换原 Forms::dataList / dataView / dataCount / searchCondition / enumSubids / enumsData
 */
interface FormQueryServiceInterface
{
    /**
     * 列表数据
     * @param array $param ['fid','page','pagesize','fields','order','by','joins','where','noinput','cacheTime', ...]
     */
    public function list(array $param): OutputInterface;

    /**
     * 单条数据
     * @param int $fid 表单 ID
     * @param int $id 数据 ID
     * @param string $fields 字段
     * @param int $cacheTime 缓存秒数
     * @param array $options 透传给钩子
     */
    public function view(int $fid, int $id, string $fields = '*', int $cacheTime = 0, array $options = []): OutputInterface;

    /**
     * 数量统计
     */
    public function count(array $param): OutputInterface;

    /**
     * 生成筛选条件（内部辅助，保留 public 供外部调用）
     */
    public function buildSearchCondition(array $param): OutputInterface;

    /**
     * 联动数据某 ID 下的所有子 ID
     */
    public function enumSubids(string $egroup, int $evalue = 0): OutputInterface;

    /**
     * 联动菜单数据（供 validCheck 使用）
     */
    public function enumsData(string $egroup): OutputInterface;
}
