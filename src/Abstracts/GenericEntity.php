<?php
/**
 * 通用实体兜底类
 *
 * 用于无专属 Entity 子类的数据表，完全继承 EntityAbstract 能力
 * （toArray / setRelation / getRelation / fill / has / casts / hidden / jsonSerialize）。
 *
 * 业务侧无论是否定义专属 Entity，均可用一致的对象式 API：
 *   $row = $repo->fetch('id,name');   // 返回 GenericEntity 或 XxxEntity
 *   $row->name;                       // 经 casts 类型转换
 *   $row->setRelation('foo', $bar);   // 挂载关联数据
 *   $row->toArray();                  // 转数组（过滤 hidden 字段）
 *   json_encode($row);               // 走 jsonSerialize
 *
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

class GenericEntity extends EntityAbstract
{
}
