<?php
declare(strict_types=1);

namespace SlimCMS\Helper;

use SlimCMS\Core\Session;

/**
 * PSR-7 兼容的图形验证码生成器
 */
class Captcha
{
    private static string $charset = '123456789';
    private static int $codelen = 4;
    private static int $width = 80;
    private static int $height = 30;
    private static string $font = CSDATA . 'fonts/INDUBITA.TTF';
    private static int $fontsize = 17;
    private static int $lineNum = 10;
    private static int $snowNum = 20;
    /** @var string[] */
    private static array $snows = ['*', '¤', '※', '☆', '§', '^', '$', '#'];

    /**
     * 生成验证码并返回 PNG 二进制数据
     *
     * @param Session $session
     * @param array $param 可选覆盖：charset/codelen/width/height/font/fontsize/lineNum/snowNum/snows
     * @return string PNG 二进制
     */
    public static function generate(Session $session, array $param = []): string
    {
        if (!empty($param['charset'])) self::$charset = (string)$param['charset'];
        if (!empty($param['codelen'])) self::$codelen = (int)$param['codelen'];
        if (!empty($param['width'])) self::$width = (int)$param['width'];
        if (!empty($param['height'])) self::$height = (int)$param['height'];
        if (!empty($param['font'])) self::$font = (string)$param['font'];
        if (!empty($param['fontsize'])) self::$fontsize = (int)$param['fontsize'];
        if (!empty($param['lineNum'])) self::$lineNum = (int)$param['lineNum'];
        if (!empty($param['snowNum'])) self::$snowNum = (int)$param['snowNum'];
        if (!empty($param['snows'])) self::$snows = (array)$param['snows'];

        $img = self::createBg();
        $code = self::createCode($session);
        self::createLine($img);
        self::createFont($img, $code);

        ob_start();
        imagepng($img);
        $png = (string)ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    /**
     * @return \GdImage|resource
     */
    private static function createBg()
    {
        $img = imagecreatetruecolor(self::$width, self::$height);
        $color = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, self::$height, self::$width, 0, $color);
        return $img;
    }

    private static function createCode(Session $session): string
    {
        $code = '';
        $max = strlen(self::$charset) - 1;
        for ($i = 0; $i < self::$codelen; $i++) {
            $code .= self::$charset[mt_rand(0, $max)];
        }
        $session->set('VerifyCode', strtolower($code));
        return $code;
    }

    /**
     * @param \GdImage|resource $img
     */
    private static function createLine($img): void
    {
        for ($i = 0; $i < self::$lineNum; $i++) {
            $color = imagecolorallocate($img, mt_rand(0, 156), mt_rand(0, 156), mt_rand(0, 156));
            imageline($img, mt_rand(0, self::$width), mt_rand(0, self::$height),
                mt_rand(0, self::$width), mt_rand(0, self::$height), $color);
        }
        for ($i = 0; $i < self::$snowNum; $i++) {
            $str = self::$snows[(int)\array_rand(self::$snows, 1)];
            $color = imagecolorallocate($img, mt_rand(100, 225), mt_rand(100, 225), mt_rand(100, 225));
            imagestring($img, mt_rand(1, 5), mt_rand(0, self::$width), mt_rand(0, self::$height), $str, $color);
        }
    }

    /**
     * @param \GdImage|resource $img
     */
    private static function createFont($img, string $code): void
    {
        $_x = (int)(self::$width / self::$codelen);
        for ($i = 0; $i < self::$codelen; $i++) {
            $color = imagecolorallocate($img, mt_rand(0, 156), mt_rand(0, 156), mt_rand(0, 156));
            imagettftext(
                $img,
                self::$fontsize,
                mt_rand(-30, 30),
                $_x * $i + mt_rand(1, 2),
                (int)(self::$height / 1.4),
                $color,
                self::$font,
                $code[$i]
            );
        }
    }
}
