<?php
/**
 * 通用数据仓库兜底类
 *
 * 用于表单数据表未生成对应 Repository 类的场景（如回收站还原、任意表单字段清理），
 * 通过 tableName 参数显式指定表名，API 与常规仓库完全一致。
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Abstracts;

use Slim\App;
use SlimCMS\Core\Form\FormQueryServiceInterface;
use SlimCMS\Core\Form\FormWriteServiceInterface;
use SlimCMS\Core\Redis;

class GenericRepository extends RepositoryAbstract
{
    public function __construct(
        App $app,
        FormWriteServiceInterface $formWrite,
        FormQueryServiceInterface $formQuery,
        Redis $redis,
        string $tableName = ''
    ) {
        if ($tableName !== '') {
            $this->forceTableName = $tableName;
        }
        parent::__construct($app, $formWrite, $formQuery, $redis);
    }

    /**
     * 单条查询统一返回对象，与实体仓库的 ->field 访问方式保持一致
     */
    public function fetch(string $field, int $cacheTime = 0): ?object
    {
        $data = parent::fetch($field, $cacheTime);
        return !empty($data) ? (object)$data : null;
    }
}
