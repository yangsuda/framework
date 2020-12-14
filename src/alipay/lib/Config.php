<?php
/**
 * 配置信息
 */

namespace alipay\lib;

class Config
{
    private static $cfg = [];

    public static function get($name)
    {
        return aval(self::$cfg, $name, false);
    }

    public static function set($data)
    {
        self::$cfg = $data;
    }
}
