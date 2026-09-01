<?php
/**
 * 通用数据仓库兜底类
 *
 * 用于表单数据表未生成对应 Repository 类的场景（如回收站还原、任意表单字段清理），
 * 通过 tableName 参数显式指定表名，API 与常规仓库完全一致。
 * 默认 $entityClass 为 null，fetch/fetchList/list 返回 GenericEntity，
 * 业务侧可用 ->field、toArray()、setRelation() 等完整 Entity API。
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
}
