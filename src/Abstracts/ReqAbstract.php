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
    protected $joins = [];

    public function getReq()
    {
        $clone = clone $this;
        return $clone;
    }

    public function getWhere(array $param): array
    {
        foreach ($param as $k => $v) {
            if (is_callable([$this, $k])) {
                $this->$k($param, $v);
            }
        }
        return $this->where;
    }

    public function getJoins(): array
    {
        return array_unique($this->joins);
    }

    protected function start(array $param, $words = null): void
    {
        if (!empty($words) && !is_numeric($words)) {
            $words = strtotime($words);
        }
        $field = $param['dateField'] ?? 'main.createtime';
        $words && $this->where[] = self::t()->field($field, $words, '>=');
    }

    protected function end(array $param, $words = null): void
    {
        if (!empty($words) && !is_numeric($words)) {
            $words = strtotime($words);
        }
        $field = $param['dateField'] ?? 'main.createtime';
        $words && $this->where[] = self::t()->field($field, $words, '<=');
    }

    protected function ids(array $param, $words = null): void
    {
        isset($words) && $this->where['id'] = is_array($words) ? $words : explode(',', (string)$words);
    }
}
