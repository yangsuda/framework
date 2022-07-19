<?php

/**
 * 文件、文件夹操作类
 * @author zhucy
 * @date 2019.09.18
 */

namespace SlimCMS\Helper;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LoggerInterface;

class File
{

    /**
     * 生成文件夹
     * @param $dir
     * @param int $mode
     * @param bool $makeindex
     * @return bool
     */
    public static function mkdir($dir, $mode = 0777, $makeindex = TRUE)
    {
        if (!is_dir($dir)) {
            self::mkdir(dirname($dir), $mode, $makeindex);
            @mkdir($dir, $mode);
            if (!empty($makeindex)) {
                @touch($dir . '/index.html');
                @chmod($dir . '/index.html', 0777);
            }
        }
        return true;
    }

    /**
     * 日志记录
     * @param string $dir
     * @param string $logName
     * @param string $format
     * @return Logger
     */
    public static function log(string $dir = '', string $logName = 'slimCMS', string $format = 'Y-m-d'): Logger
    {
        static $loggers = [];
        $key = $dir . $format . $logName;
        if (empty($loggers[$key])) {
            //日期格式
            $dateFormat = "Y-m-d H:i:s";
            //输出格式
            $output = "[%datetime%] - [%level_name%] - [%channel%]\n%message% %context%\n";
            //创建一个格式化器
            $formatter = new LineFormatter($output, $dateFormat);

            $dir = trim($dir, '/');
            $config = getConfig();
            $suffix = substr(md5($config['settings']['security']['authkey']), 5, -10);
            $dir = ($dir ? $dir . '_' : '') . $suffix;

            $path = CSDATA . $dir . '/' . date($format) . '.log';
            $logger = new Logger($logName);
            $handler = new StreamHandler($path, 100);
            $handler->setFormatter($formatter);
            $loggers[$key] = $logger->setHandlers([$handler]);
        }
        return $loggers[$key];
    }

    /**
     * 文件夹复制
     * @param string $sourceDir
     * @param string $targetDir
     * @return bool
     */
    public static function copyDir(string $sourceDir, string $targetDir): bool
    {
        if (!is_dir($sourceDir)) {
            return false;
        }
        $dh = @dir($sourceDir);
        self::mkdir($targetDir);
        while (($file = $dh->read()) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir($sourceDir . '/' . $file)) {
                    self::copyDir($sourceDir . '/' . $file, $targetDir . '/' . $file);
                } else {
                    @copy($sourceDir . '/' . $file, $targetDir . '/' . $file);
                }
            }
        }
        $dh->close();
        return true;
    }

    /**
     * 删除文件或文件夹
     * @param $file
     * @return bool
     */
    public static function delFiles(string $file): bool
    {
        if (empty($file)) {
            return false;
        }
        if (is_file($file)) {
            return unlink($file);
        } elseif (is_dir($file)) {
            return self::delDir($file);
        }
        return false;
    }

    /**
     * 删除文件夹
     * @param $dir
     * @return bool
     */
    private static function delDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $dh = dir($dir);
        while ($filename = $dh->read()) {
            if ($filename == '.' || $filename == '..') {
                continue;
            } elseif (is_file($dir . '/' . $filename)) {
                unlink($dir . '/' . $filename);
            } elseif (is_dir($dir . '/' . $filename)) {
                self::delDir($dir . '/' . $filename);
            }
        }
        $dh->close();
        is_dir($dir) && @rmdir($dir);
        return true;
    }
}
