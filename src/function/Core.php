<?php

use SlimCMS\Interfaces\UploadInterface;

/**
 * 获取数组中某一元素
 * @param $arr 数组
 * @param $val 元素
 * @param $default 都不存在时的默认值
 */
function aval(array $arr, string|int $val, $default = null)
{
    $arr = empty($arr) ? array() : (array)$arr;
    if (($pos = strpos((string)$val, '/')) !== false) {
        $str1 = substr($val, 0, $pos);
        $str2 = trim(substr($val, $pos), '/');
        if (!isset($arr[$str1])) {
            return $default;
        }
        return aval($arr[$str1], $str2, $default);
    }
    return isset($arr[$val]) ? $arr[$val] : $default;
}

/**
 * 获取配置信息
 * @return array|mixed
 */
function getConfig()
{
    static $cfg = [];
    if (empty($cfg)) {
        $cfg = require_once CSDATA . 'ConfigCache.php';
        $cfg['settings'] = [];
        if (is_file(CSROOT . 'config/settings.php')) {
            $settings = require_once CSROOT . 'config/settings.php';
            $cfg = array_merge($cfg, $settings);
        }
        //防止最后不加/导致ueditor等加载出错
        $cfg['cfg']['basehost'] = rtrim($cfg['cfg']['basehost'], '/') . '/';
    }
    return $cfg;
}

/**
 * 版本比较
 * @param $ver
 * @param string $operator
 * @return bool
 */
function versionCheck($ver, $operator = '<=')
{
    if (strpos($operator, '<') !== false) {
        return !defined('VERSION') || defined('VERSION') && version_compare(VERSION, $ver, $operator);
    }
    return defined('VERSION') && version_compare(VERSION, $ver, $operator);
}
