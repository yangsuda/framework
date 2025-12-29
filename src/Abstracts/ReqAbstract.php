<?php
/**
 * 查询类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

abstract class ReqAbstract extends ServiceAbstract
{
    protected $where = [];

    public function getWhere(array $param): array
    {
        $clone = clone $this;
        foreach ($param as $k => $v) {
            if (is_callable([$this, $k])) {
                $clone->$k($param, $v);
            }
        }
        return $clone->where;
    }

    protected function start(array $param, $words = null): void
    {
        if (!empty($words) && !is_numeric($words)) {
            $words = strtotime($words);
        }
        $field = $param['dateField'] ?? 'createtime';
        $words && $this->where[] = self::t()->field($field, $words, '>=');
    }

    protected function end(array $param, $words = null): void
    {
        if (!empty($words) && !is_numeric($words)) {
            $words = strtotime($words);
        }
        $field = $param['dateField'] ?? 'createtime';
        $words && $this->where[] = self::t()->field($field, $words, '<=');
    }

    protected function ids(array $param, $words = null): void
    {
        isset($words) && $this->where['id'] = is_array($words) ? $words : explode(',', (string)$words);
    }
}
