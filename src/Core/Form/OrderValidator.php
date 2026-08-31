<?php
/**
 * 排序字段校验器
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use Psr\Container\ContainerInterface;
use SlimCMS\Core\Redis;
use SlimCMS\Core\Table;

/**
 * 排序字段白名单校验
 *
 * 替换原 Forms::validOrder()，避免业务层重复 SQL 校验。
 */
final class OrderValidator
{
    public function __construct(
        private ContainerInterface $container,
        private Redis $redis,
    ) {}

    /**
     * 校验并返回安全的 order 表达式
     *
     * @param int $fid 表单 ID
     * @param string $order 用户传入的 order
     * @param bool $force 是否强制使用用户传入（不校验白名单）
     */
    public function validate(int $fid, string $order = '', bool $force = false): string
    {
        if ($force === true) {
            return $order;
        }
        if (empty($order)) {
            $row = $this->fieldsTable()->withWhere(['formid' => $fid, 'available' => 1, 'defaultorder' => [1, 2]])->fetch();
            $order = 'main.id';
            if ($row) {
                $by = $row['defaultorder'] == 1 ? 'desc' : 'asc';
                $order = 'main.' . $row['identifier'] . ' ' . $by . ',' . $order;
            }
            return $order;
        }
        if ($order == 'rand&#040;&#041;' || $order == 'rand()') {
            return 'rand()';
        }

        $fields = (array)$order;
        if (strpos((string)$order, ',')) {
            $fields = explode(',', str_replace([' desc', ' asc'], '', $order));
        }

        $valid = true;
        foreach ($fields as $v) {
            $v = trim($v);
            if ($v == 'id') {
                continue;
            }
            $where = ['formid' => $fid, 'available' => 1, 'identifier' => $v, 'orderby' => 1];
            if (empty($v) || !$this->fieldsTable()->withWhere($where)->count()) {
                $valid = false;
                break;
            }
        }
        return $valid ? $order : 'main.id';
    }

    private function fieldsTable(): Table
    {
        $app = $this->container->get(\Slim\App::class);
        return (new Table($app, $this->redis))->setTableName('forms_fields');
    }
}
