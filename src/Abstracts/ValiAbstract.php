<?php
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use SlimCMS\Core\Request;
use SlimCMS\Core\Response;
use SlimCMS\Helper\Crypt;
use SlimCMS\Interfaces\OutputInterface;

abstract class ValiAbstract extends BaseAbstract
{

    /**
     * 获取某字段对应文字
     * @param string $field
     * @return string
     * @throws \SlimCMS\Error\TextException
     */
    protected static function getFieldText(string $field): string
    {
        $fields = self::getTableFields();
        return aval($fields, $field, '');
    }

    /**
     * 获取某表字段数据
     * @return array
     * @throws \SlimCMS\Error\TextException
     */
    protected static function getTableFields(): array
    {
        static $fields = [];
        if (empty($fields)) {
            $table = preg_replace('/vali$/', '', strtolower(substr(strrchr(get_called_class(), '\\'), 1)));
            $formId = (int)self::t('forms')->withWhere(['table' => $table])->fetch('id');
            $list = self::t('forms_fields')->withWhere(['formid' => $formId])->fetchList('title,identifier');
            $fields = array_column($list, 'title', 'identifier');
        }
        return $fields;
    }
}