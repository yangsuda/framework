<?php
/**
 * 返回类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

abstract class RespAbstract extends ServiceAbstract
{

    protected $respExtraData = [];

    public function getRespExtraRowData(array &$data, string $respExtraRowFields = ''): void
    {
        $param = $respExtraRowFields ? explode(',', $respExtraRowFields) : [];
        $clone = clone $this;
        foreach ($param as $v) {
            if (is_callable([$this, $v])) {
                $clone->$v($data);
            }
        }
    }

    public function getRespExtraData(array $data, string $respExtraFields = ''): array
    {
        $param = $respExtraFields ? explode(',', $respExtraFields) : [];
        $clone = clone $this;
        foreach ($param as $v) {
            if (is_callable([$this, $v])) {
                $clone->$v($data);
            }
        }
        return $clone->respExtraData;
    }
}
