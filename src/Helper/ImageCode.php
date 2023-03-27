<?php
/**
 * 图形验证码
 */
declare(strict_types=1);

namespace SlimCMS\Helper;

class ImageCode
{
    private static $charset = "123456789";   //随机因子
    private static $code;     //验证码文字
    private static $codelen = 4;    //验证码显示几个文字
    private static $width = 80;   //验证码宽度
    private static $height = 30;   //验证码高度
    private static $img;       //验证码资源句柄
    private static $font = CSDATA . 'fonts/INDUBITA.TTF';     //指定的字体
    private static $fontsize = 17;  //指定的字体大小
    private static $lineNum = 10;//线条数量
    private static $snowNum = 20;//雪花数量
    private static $snows = ['*', '¤', '※', '☆', '§', '^', '$', '#'];//雪花各类
    /**
     * 创建随机码
     * @return mixed
     */
    private static function createCode()
    {
        $_leng = strlen(self::$charset) - 1;
        for ($i = 1; $i <= self::$codelen; $i++) {
            self::$code .= self::$charset[mt_rand(0, $_leng)];
        }
        isset($_SESSION) ? '' : session_start();
        $_SESSION['VerifyCode'] = strtolower(self::$code);
        return self::$code;
    }

    /**
     * 创建背景
     * @return void
     */
    private static function createBg()
    {
        //创建画布 给一个资源jubing
        self::$img = imagecreatetruecolor(self::$width, self::$height);
        //背景颜色
        $color = imagecolorallocate(self::$img, 255, 255, 255);
        //画出一个矩形
        imagefilledrectangle(self::$img, 0, self::$height, self::$width, 0, $color);
    }

    /**
     * 创建字体
     * @return void
     */
    private static function createFont()
    {
        $_x = (self::$width / self::$codelen);   //字体长度
        for ($i = 0; $i < self::$codelen; $i++) {
            //文字颜色
            $color = imagecolorallocate(self::$img, mt_rand(0, 156), mt_rand(0, 156), mt_rand(0, 156));
            //资源句柄 字体大小 倾斜度 字体长度  字体高度  字体颜色  字体  具体文本
            imagettftext(self::$img, self::$fontsize, mt_rand(-30, 30), $_x * $i + mt_rand(1, 2), (int)(self::$height / 1.4), $color, self::$font, self::$code[$i]);
        }
    }

    /**
     * 随机线条
     * @return void
     */
    private static function createLine()
    {
        //随机线条
        for ($i = 0; $i < self::$lineNum; $i++) {
            $color = imagecolorallocate(self::$img, mt_rand(0, 156), mt_rand(0, 156), mt_rand(0, 156));
            imageline(self::$img, mt_rand(0, self::$width), mt_rand(0, self::$height), mt_rand(0, self::$width), mt_rand(0, self::$height), $color);
        }
        //随机雪花
        for ($i = 0; $i < self::$snowNum; $i++) {
            $str = self::$snows[array_rand(self::$snows, 1)];
            $color = imagecolorallocate(self::$img, mt_rand(100, 225), mt_rand(100, 225), mt_rand(100, 225));
            imagestring(self::$img, mt_rand(1, 5), mt_rand(0, self::$width), mt_rand(0, self::$height), $str, $color);
        }
    }

    /**
     * 输出背景
     * @return void
     */
    private static function outPut()
    {
        //生成标头
        header('Content-type:image/png');
        //输出图片
        imagepng(self::$img);
        //销毁结果集
        imagedestroy(self::$img);
        exit;
    }

    /**
     * 对外输出
     * @return void
     */
    public static function doimg(array $param = [])
    {
        !empty($param['charset']) && self::$charset = $param['charset'];//随机因子
        !empty($param['codelen']) && self::$codelen = $param['codelen'];    //验证码显示几个文字
        !empty($param['width']) && self::$width = $param['width'];   //验证码宽度
        !empty($param['height']) && self::$height = $param['height'];   //验证码高度
        !empty($param['font']) && self::$font = $param['font'];     //指定的字体
        !empty($param['fontsize']) && self::$fontsize = $param['fontsize'];  //指定的字体大小
        !empty($param['lineNum']) && self::$lineNum = $param['lineNum'];  //线条数量
        !empty($param['snowNum']) && self::$snowNum = $param['snowNum'];  //雪花数量
        !empty($param['snows']) && self::$snows = $param['snows'];  //雪花各类
        //加载背景
        self::createBg();
        //加载文件
        self::createCode();
        //加载线条
        self::createLine();
        //加载字体
        self::createFont();
        //加载背景
        self::outPut();
    }

    /**
     * 验证验证码
     * @param $code
     * @return bool
     */
    public static function checkCode($code)
    {
        isset($_SESSION) ? '' : session_start();
        if ($code && aval($_SESSION,'VerifyCode') == strtolower($code)) {
            self::clearCode();
            return true;
        }
        self::clearCode();
        return false;
    }

    /**
     * 清除验证码
     * @return void
     */
    private static function clearCode()
    {
        isset($_SESSION) ? '' : session_start();
        unset($_SESSION['VerifyCode']);
    }
}
