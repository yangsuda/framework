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
    protected $relations = [];

    /**
     * 返回每行的额外数据
     * @param array $data
     * @param string $respExtraRowFields
     * @return void
     */
    public function getRespExtraRowData(array &$data, TableAbstract $table): void
    {
        $fields = $table->getRespExtraRowFields() ? explode(',', $table->getRespExtraRowFields()) : [];
        $clone = clone $this;
        $clone->getRelation($data, $table);
        if ($fields) {
            foreach ($data as &$v) {
                foreach ($fields as $field) {
                    if (is_callable([$this, $field])) {
                        $clone->$field($v, $table);
                    }
                }
            }
        }
    }

    /**
     * 返回额外数据
     * @param array $data
     * @param string $respExtraFields
     * @return array
     */
    public function getRespExtraData(array $data, TableAbstract $table): array
    {
        $fields = $table->getRespExtraFields() ? explode(',', $table->getRespExtraFields()) : [];
        $clone = clone $this;
        foreach ($fields as $v) {
            if (is_callable([$this, $v])) {
                $clone->$v($data, $table);
            }
        }
        return $clone->respExtraData;
    }

    /**
     * 关联查询
     * @param array $data
     * @param string $respExtraRowFields
     * @return void
     */
    public function getRelation(array &$data, TableAbstract $table): void
    {
        $fields = $table->getRespExtraRowFields() ? explode(',', $table->getRespExtraRowFields()) : [];
        foreach ($fields as $field) {
            $func = $field . 'Relation';
            if (is_callable([$this, $func])) {
                $this->relations[$field] = $this->$func($data, $table);
            }
        }
    }
}
