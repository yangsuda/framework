<?php
/**
 * 表单值转换工具（无状态纯函数）
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use Psr\Container\ContainerInterface;
use SlimCMS\Helper\Str;
use SlimCMS\Helper\Time;
use SlimCMS\Interfaces\UploadInterface;

/**
 * 数据库查询结果 → 展示值 转换
 *
 * 替换原 Forms::exchangeFieldValue()，无副作用、可独立测试。
 * 输入：字段 schema 原始数组 + 单行数据 + 容器（用于 uploader/sysenum）
 * 输出：补充 _xxx 字段后的行
 */
final class FormValueTransformer
{
    /**
     * @param array $fieldSchemas fieldList() 原始行数组
     * @param array $row 单行数据
     * @param ContainerInterface $container 用于解析 uploader / Table（sysenum 联动）
     */
    public static function exchange(array $fieldSchemas, array $row, ContainerInterface $container): array
    {
        if (empty($fieldSchemas)) {
            return [];
        }
        if (isset($row['createtime'])) {
            $row['_createtime'] = $row['createtime'] ? Time::gmdate($row['createtime'], 'dt') : '';
        }

        $config = $container->get('cfg');
        $uploader = $container->get(UploadInterface::class);

        foreach ($fieldSchemas as $field) {
            $identifier = $field['identifier'];
            if (!isset($row[$identifier])) {
                continue;
            }
            !empty($field['units']) && $row[$identifier . '_units'] = $field['units'];

            $rules = [];
            if (!empty($field['rules'])) {
                $rules = json_decode($field['rules'], true);
                // 动态规则由 SchemaService 预处理，这里降级处理
                if (!empty($rules) && count($rules) == 1) {
                    $rules = self::resolveRulesFromTable($rules, $container);
                }
            }

            switch ($field['datatype']) {
                case 'htmltext':
                    $row['_' . $identifier] = stripslashes($row[$identifier]);
                    break;
                case 'price':
                    if ($row[$identifier] && $row[$identifier] != '0.00') {
                        $row['_' . $identifier] = $row[$identifier] = (float)$row[$identifier];
                    } else {
                        $row['_' . $identifier] = '';
                    }
                    break;
                case 'date':
                    if ($row[$identifier]) {
                        $row[$identifier] = (int)$row[$identifier];
                        $row['_' . $identifier] = Time::gmdate($row[$identifier]);
                    } else {
                        $row['_' . $identifier] = $row[$identifier] = '';
                    }
                    break;
                case 'datetime':
                    if ($row[$identifier]) {
                        $row[$identifier] = (int)$row[$identifier];
                        $row['_' . $identifier] = Time::gmdate($row[$identifier], 'dt');
                    } else {
                        $row['_' . $identifier] = $row[$identifier] = '';
                    }
                    break;
                case 'month':
                    if ($row[$identifier]) {
                        $row[$identifier] = (int)$row[$identifier];
                        $row['_' . $identifier] = date('Y-m', $row[$identifier]);
                    } else {
                        $row['_' . $identifier] = $row[$identifier] = '';
                    }
                    break;
                case 'float':
                    if ($row[$identifier]) {
                        $row['_' . $identifier] = $row[$identifier] = (float)$row[$identifier];
                    } else {
                        $row['_' . $identifier] = $row[$identifier] = '';
                    }
                    break;
                case 'checkbox':
                    if (!empty($row[$identifier])) {
                        $arr = [];
                        $arrMore = [];
                        foreach (explode(',', (string)$row[$identifier]) as $_v) {
                            if (!empty($_v)) {
                                $name = $rules[$_v] ?? null;
                                $arr[] = $name;
                                $arrMore[] = ['id' => $_v, 'name' => $name];
                            }
                        }
                        $row['_' . $identifier] = implode('、', $arr);
                        $row['__' . $identifier] = $arrMore;
                    }
                    break;
                case 'stepselect':
                    if (!empty($row[$identifier])) {
                        $table = $container->get(\Slim\App::class);
                        $redis = $container->get(\SlimCMS\Core\Redis::class);
                        $t = (new \SlimCMS\Core\Table($table, $redis))->setTableName('sysenum');
                        $enum = $t->withWhere(['egroup' => $field['egroup'], 'evalue' => $row[$identifier]])->fetch();
                        $row['_' . $identifier] = !empty($enum['alias']) ? $enum['alias'] : ($enum['ename'] ?? '');
                    } else {
                        $row['_' . $identifier] = '';
                    }
                    break;
                case 'select':
                case 'radio':
                    $row['_' . $identifier] = $rules[$row[$identifier]] ?? null;
                    break;
                case 'img':
                    $width = (int)($config['imgWidth'] ?? 800);
                    $height = (int)($config['imgHeight'] ?? 800);
                    $row['_' . $identifier] = $uploader->copyImage($row[$identifier], $width, $height);
                    $row['_' . $identifier . '_thumbnail'] = $uploader->copyImage($row[$identifier], 160, 160);
                    break;
                case 'imgs':
                    $img = !empty($row[$identifier]) ? json_decode($row[$identifier], true) : [];
                    if (is_array($img)) {
                        foreach ($img as $k1 => $v1) {
                            $v1['originalImg'] = $uploader->copyImage($v1['img']);
                            $v1['img'] = $uploader->copyImage($v1['img'], 160, 160);
                            $img[$k1] = $v1;
                        }
                    }
                    $row['_' . $identifier] = $img;
                    break;
                case 'media':
                case 'addon':
                    $row['_' . $identifier] = $row[$identifier] ? trim($config['basehost'], '/') . $row[$identifier] : '';
                    break;
                case 'addons':
                case 'serialize':
                    $row['_' . $identifier] = json_decode($row[$identifier], true);
                    break;
                case 'int':
                    if (!empty($field['rules'])) {
                        $result = self::analysisRulesForValue($rules, $container);
                        if ($result) {
                            $table = $container->get(\Slim\App::class);
                            $redis = $container->get(\SlimCMS\Core\Redis::class);
                            $t = (new \SlimCMS\Core\Table($table, $redis))->setTableName($result['table']);
                            $row['_' . $identifier] = $t->withWhere([$result['value'] => $row[$identifier]])->fetch($result['name']);
                        } else {
                            $row['_' . $identifier] = $row[$identifier] = (int)$row[$identifier];
                        }
                    } else {
                        $row['_' . $identifier] = $row[$identifier] = (int)$row[$identifier];
                    }
                    break;
                default:
                    $row['_' . $identifier] = Str::htmlspecialchars(html_entity_decode((string)$row[$identifier]), 'de');
                    break;
            }
        }
        return $row;
    }

    /**
     * 动态规则解析（从其它表查询的映射），委托 FormSchemaService
     */
    private static function resolveRulesFromTable(array $rules, ContainerInterface $container): array
    {
        try {
            $service = $container->get(FormSchemaServiceInterface::class);
            return $service->resolveDynamicRules($rules);
        } catch (\Throwable $e) {
            return $rules;
        }
    }

    /**
     * analysisRules（从 rules JSON 反查表/字段配置）
     */
    private static function analysisRulesForValue(array $rules, ContainerInterface $container): array
    {
        try {
            $service = $container->get(FormSchemaServiceInterface::class);
            return $service->analysisRules($rules);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
