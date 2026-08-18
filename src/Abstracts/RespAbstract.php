<?php
/**
 * 返回类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use SlimCMS\Error\TextException;

abstract class RespAbstract extends BaseAbstract
{
    /**
     * 返回每行的额外数据
     * @param array $list
     * @param string $respExtraRowFields
     * @return void
     */
    public function getRespExtraRowData(array &$list, RepositoryAbstract $table): void
    {
        $fields = $table->getRespExtraRowFields() ? explode(',', $table->getRespExtraRowFields()) : [];
        if ($fields) {
            $clone = clone $this;
            foreach ($fields as $field) {
                if (is_callable([$this, $field])) {
                    $clone->$field($list, $table);
                }
            }
        }
    }

    /**
     * 返回额外数据
     * @param array $data
     * @param string $respExtraFields
     * @return void
     */
    public function getRespExtraData(array &$data, RepositoryAbstract $table): void
    {
        $fields = $table->getRespExtraFields() ? explode(',', $table->getRespExtraFields()) : [];
        $clone = clone $this;
        foreach ($fields as $v) {
            $func = $field . 'Extra';
            if (is_callable([$this, $v])) {
                $clone->$v($data, $table);
            }
        }
    }

    /**
     * @template T of RepositoryAbstract
     * @param class-string<T> $className
     * @return T|null
     */
    public function r(string $className): ?RepositoryAbstract
    {
        if (!class_exists($className)) {
            throw new TextException(503, "Repository class not found");
        }
        return $this->i($className);
    }
}
