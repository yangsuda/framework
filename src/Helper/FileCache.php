<?php
/**
 * 文件缓存类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Helper;

class FileCache
{
    private static function getCacheFile($key)
    {
        $key = md5($key);
        if (version_compare(VERSION, '3.0.1', '>=')) {
            $dir = CSDATA . 'fileCache/' . $key[0] . $key[1] . '/';
        } else {
            $dir = CSDATA . 'fileCache/' . $key[0] . '/';
        }
        File::mkdir($dir);
        return $dir . $key . '.txt';
    }

    /**
     * 保存缓存数据
     * @param $key
     * @param $value
     * @param $ttl
     * @return bool
     */
    public static function set($key, $value, $ttl)
    {
        $cacheFile = self::getCacheFile($key);
        $data = [];
        $data['value'] = $value;
        $data['timestamp'] = TIMESTAMP + $ttl;
        $str = json_encode($data);
        file_put_contents($cacheFile, $str);
        return true;
    }

    /**
     * 获取缓存数据
     * @param $key
     * @return |null
     */
    public static function get($key)
    {
        $cacheFile = self::getCacheFile($key);
        if (!is_file($cacheFile)) {
            return null;
        }
        $str = file_get_contents($cacheFile);
        $data = json_decode($str, true);
        if ($data['timestamp'] < TIMESTAMP) {
            unlink($cacheFile);
            return null;
        }
        return $data['value'];
    }

    /**
     * 删除缓存文件
     * @param $key
     * @return bool
     */
    public static function del($key)
    {
        $cacheFile = self::getCacheFile($key);
        is_file($cacheFile) && unlink($cacheFile);
        return true;
    }
}
