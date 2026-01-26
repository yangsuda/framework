<?php
/**
 * 查询类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use Respect\Validation\Exceptions\ValidationException;
use SlimCMS\Error\TextException;

abstract class ReqAbstract extends ServiceAbstract
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
                    $class = '\App\Model\vali\\' . ucfirst(self::getTableName()) . 'Vali';
                    if (!empty($class) && method_exists($class, $k) && is_callable([$class, $k])) {
                        $callback = $class . '::' . $k;
                        try {
                            $callback()->assert($v);
                        } catch (ValidationException $e) {
                            $messages = $e->getMessages();
                            foreach ($messages as $message) {
                                throw new TextException(21000, ['msg' => $message]);
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
