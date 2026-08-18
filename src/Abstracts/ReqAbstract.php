<?php
/**
 * 查询类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use Respect\Validation\Exceptions\ValidationException;
use SlimCMS\Core\Table;
use SlimCMS\Error\TextException;

abstract class ReqAbstract extends BaseAbstract
{
    protected $where = [];
    protected $joins = [];

    public function getReq()
    {
        $clone = clone $this;
        return $clone;
    }

    public function getWhere(array $param, array $valiIgnore = []): array
    {
        foreach ($param as $k => $v) {
            if (is_callable([$this, $k])) {
                if (empty($valiIgnore) || aval($valiIgnore, $k) !== true) {
                    //有效性校验
                    $class = '\app\Model\vali\\' . ucfirst($this->getTableName()) . 'Vali';
                    if (!empty($class) && method_exists($class, $k) && ($obj = $this->i($class)) && is_callable([$obj, $k])) {
                        $callback = $obj->$k();
                        try {
                            $callback->assert($v);
                        } catch (ValidationException $e) {
                            $messages = $e->getMessages();
                            foreach ($messages as $message) {
                                throw new TextException(21000, $message);
                            }
                        }
                    }
                }
                $this->$k($param, $v);
            }
        }
        return $this->where;
    }

    private function getTableName(): string
    {
        return preg_replace('/req$/', '', strtolower(substr(strrchr(get_called_class(), '\\'), 1)));
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
        $words && $this->where[] = $this->i(Table::class)->field($field, $words, '>=');
    }

    protected function end(array $param, $words = null): void
    {
        if (!empty($words) && !is_numeric($words)) {
            $words = strtotime($words);
        }
        $field = $param['dateField'] ?? 'main.createtime';
        $words && $this->where[] = $this->i(Table::class)->field($field, $words, '<=');
    }

    protected function ids(array $param, $words = null): void
    {
        isset($words) && $this->where['id'] = is_array($words) ? $words : explode(',', (string)$words);
    }

    protected function id(array $param, $words = null): void
    {
        isset($words) && $this->where['id'] = $words;
    }
}
