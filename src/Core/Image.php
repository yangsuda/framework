<?php
/**
 * 图片处理类
 * @author zhucy
 */

namespace SlimCMS\Core;

use SlimCMS\Abstracts\ModelAbstract;
use SlimCMS\Interfaces\UploadInterface;

class Image extends ModelAbstract
{
    private static $attachinfo;
    private static $targetfile;    //图片路径
    private static $imagecreatefromfunc;
    private static $imagefunc;
    private static $attach;
    private static $cfg = [];

    private static function init()
    {
        //检测用户系统支持的图片格式
        self::$cfg['photo_type']['gif'] = FALSE;
        self::$cfg['photo_type']['jpeg'] = FALSE;
        self::$cfg['photo_type']['png'] = FALSE;
        self::$cfg['photo_type']['wbmp'] = FALSE;
        self::$cfg['photo_typenames'] = [];
        self::$cfg['photo_support'] = '';
        if (function_exists("imagecreatefromgif") && function_exists("imagegif")) {
            self::$cfg['photo_type']["gif"] = TRUE;
            self::$cfg['photo_typenames'][] = "image/gif";
            self::$cfg['photo_support'] .= "GIF ";
        }
        if (function_exists("imagecreatefromjpeg") && function_exists("imagejpeg")) {
            self::$cfg['photo_type']["jpeg"] = TRUE;
            self::$cfg['photo_typenames'][] = "image/pjpeg";
            self::$cfg['photo_typenames'][] = "image/jpeg";
            self::$cfg['photo_support'] .= "JPEG ";
        }
        if (function_exists("imagecreatefrompng") && function_exists("imagepng")) {
            self::$cfg['photo_type']["png"] = TRUE;
            self::$cfg['photo_typenames'][] = "image/png";
            self::$cfg['photo_typenames'][] = "image/xpng";
            self::$cfg['photo_support'] .= "PNG ";
        }
        if (function_exists("imagecreatefromwbmp") && function_exists("imagewbmp")) {
            self::$cfg['photo_type']["wbmp"] = TRUE;
            self::$cfg['photo_typenames'][] = "image/wbmp";
            self::$cfg['photo_support'] .= "WBMP ";
        }
    }

    private static function watermark_gd($preview = 0)
    {
        if (function_exists('imagecopy') && function_exists('imagealphablending') && function_exists('imagecopymerge')) {
            $imagecreatefunc = self::$imagecreatefromfunc;
            $imagefunc = self::$imagefunc;
            list($imagewidth, $imageheight) = self::$attachinfo;
            if (empty(self::$config['markimg'])) {
                return false;
            }
            $watermark_file = CSPUBLIC . self::$config['markimg'];
            $watermarkinfo = @getimagesize($watermark_file);
            $watermark_logo = @imagecreatefrompng($watermark_file);
            if (!$watermark_logo) {
                return false;
            }
            list($logowidth, $logoheight) = $watermarkinfo;
            $wmwidth = $imagewidth - $logowidth;
            $wmheight = $imageheight - $logoheight;
            if (is_readable($watermark_file) && $wmwidth > 10 && $wmheight > 10) {
                if (self::$config['waterpos'] == 0) {
                    self::$config['waterpos'] = mt_rand(1, 9);
                }
                $x = $y = 0;
                switch (self::$config['waterpos']) {
                    case 1:
                        $x = +5;
                        $y = +5;
                        break;
                    case 2:
                        $x = ($imagewidth - $logowidth) / 2;
                        $y = +5;
                        break;
                    case 3:
                        $x = $imagewidth - $logowidth - 5;
                        $y = +5;
                        break;
                    case 4:
                        $x = +5;
                        $y = ($imageheight - $logoheight) / 2;
                        break;
                    case 5:
                        $x = ($imagewidth - $logowidth) / 2;
                        $y = ($imageheight - $logoheight) / 2;
                        break;
                    case 6:
                        $x = $imagewidth - $logowidth - 5;
                        $y = ($imageheight - $logoheight) / 2;
                        break;
                    case 7:
                        $x = +5;
                        $y = $imageheight - $logoheight - 5;
                        break;
                    case 8:
                        $x = ($imagewidth - $logowidth) / 2;
                        $y = $imageheight - $logoheight - 5;
                        break;
                    case 9:
                        $x = $imagewidth - $logowidth - 5;
                        $y = $imageheight - $logoheight - 5;
                        break;
                }
                $dst_photo = @imagecreatetruecolor($imagewidth, $imageheight);
                if (self::$attachinfo[2] == 3) {
                    imagealphablending($dst_photo, false);//意思是不合并颜色,直接用图像颜色替换,包括透明色;
                    imagesavealpha($dst_photo, true);//意思是不要丢了图像的透明色;
                }
                $target_photo = $imagecreatefunc(self::$targetfile);
                self::$attachinfo[2] == 3 && imagesavealpha($target_photo, true);//意思是不要丢了图像的透明色;
                imagecopy($dst_photo, $target_photo, 0, 0, 0, 0, $imagewidth, $imageheight);
                imagecopy($dst_photo, $watermark_logo, $x, $y, 0, 0, $logowidth, $logoheight);
                $targetfile = !$preview ? self::$targetfile : './watermark_tmp.jpg';
                if (self::$attachinfo['mime'] == 'image/jpeg') {
                    $imagefunc($dst_photo, $targetfile, 100);
                } else {
                    $imagefunc($dst_photo, $targetfile);
                }
                self::$attach['size'] = filesize(self::$targetfile);
                return true;
            }
        }
        return false;
    }

    /**
     * 图片处理成指定大小
     * @param $file
     * @param $width
     * @param $height
     */
    public static function imageResize($file, $width = 0, $height = 0)
    {
        $width = $width ?: self::$config['imgWidth'];
        $height = $height ?: self::$config['imgHeight'];
        if (self::$config['imgFull'] == '1') {
            self::resizeNew($file, $width, $height);
        } else {
            self::resize($file, $width, $height);
        }
    }

    /**
     *  缩图片自动生成函数，来源支持bmp、gif、jpg、png
     *  但生成的小图只用jpg或png格式
     * @param string $srcFile 图片路径
     * @param string $toW 转换到的宽度
     * @param string $toH 转换到的高度
     * @return    string
     */
    public static function resize($srcFile, $toW, $toH)
    {
        self::init();
        $toFile = $srcFile;
        $info = '';
        $srcInfo = getimagesize($srcFile, $info);
        switch ($srcInfo[2]) {
            case 1:
                if (!self::$cfg['photo_type']['gif']) return FALSE;
                $im = imagecreatefromgif($srcFile);
                break;
            case 2:
                if (!self::$cfg['photo_type']['jpeg']) return FALSE;
                $im = imagecreatefromjpeg($srcFile);
                break;
            case 3:
                if (!self::$cfg['photo_type']['png']) return FALSE;
                $im = imagecreatefrompng($srcFile);
                imagesavealpha($im, true);//意思是不要丢了图像的透明色;
                break;
            case 6:
                if (!self::$cfg['photo_type']['bmp']) return FALSE;
                $im = imagecreatefromwbmp($srcFile);
                break;
        }
        $srcW = imagesx($im);
        $srcH = imagesy($im);
        if ($srcW <= $toW && $srcH <= $toH) return TRUE;
        $toWH = $toW / $toH;
        $srcWH = $srcW / $srcH;
        if ($toWH <= $srcWH) {
            $ftoW = $toW;
            $ftoH = (int)($ftoW * ($srcH / $srcW));
        } else {
            $ftoH = $toH;
            $ftoW = (int)($ftoH * ($srcW / $srcH));
        }
        if ($srcW > $toW || $srcH > $toH) {
            if (function_exists("imagecreatetruecolor")) {
                @$ni = imagecreatetruecolor($ftoW, $ftoH);
                if ($ni) {
                    if ($srcInfo[2] == 3) {
                        imagealphablending($ni, false);//意思是不合并颜色,直接用图像颜色替换,包括透明色;
                        imagesavealpha($ni, true);//意思是不要丢了图像的透明色;
                    }
                    imagecopyresampled($ni, $im, 0, 0, 0, 0, $ftoW, $ftoH, $srcW, $srcH);
                } else {
                    $ni = imagecreate($ftoW, $ftoH);
                    imagecopyresized($ni, $im, 0, 0, 0, 0, $ftoW, $ftoH, $srcW, $srcH);
                }
            } else {
                $ni = imagecreate($ftoW, $ftoH);
                imagecopyresized($ni, $im, 0, 0, 0, 0, $ftoW, $ftoH, $srcW, $srcH);
            }

            switch ($srcInfo[2]) {
                case 1:
                    imagegif($ni, $toFile);
                    break;
                case 2:
                    $jpgQuality = aval(self::$config, 'jpgQuality', 95);
                    imagejpeg($ni, $toFile, $jpgQuality);
                    break;
                case 3:
                    imagepng($ni, $toFile);
                    break;
                case 6:
                    imagebmp($ni, $toFile);
                    break;
                default:
                    return FALSE;
            }
            $ni && imagedestroy($ni);
        }
        imagedestroy($im);
        return TRUE;
    }

    /**
     *  图片自动加水印函数
     * @access    public
     * @param string $srcFile 图片源文件
     * @return    string
     */
    public static function waterImg($srcFile)
    {
        if (empty(self::$config['markimg']) || !is_file(CSPUBLIC . self::$config['markimg'])) {
            return false;
        }
        self::$targetfile = $srcFile;
        self::$attachinfo = @getimagesize($srcFile);
        if (self::$attachinfo['mime'] == 'image/gif') {
            return false;
        }
        $markimgInfo = @getimagesize(CSPUBLIC . self::$config['markimg']);
        if (self::$attachinfo[0] <= $markimgInfo[0] && self::$attachinfo[1] <= $markimgInfo[1]) {
            return false;
        }

        switch (self::$attachinfo['mime']) {
            case 'image/jpeg':
                self::$imagecreatefromfunc = function_exists('imagecreatefromjpeg') ? 'imagecreatefromjpeg' : '';
                self::$imagefunc = function_exists('imagejpeg') ? 'imagejpeg' : '';
                break;
            case 'image/png':
                self::$imagecreatefromfunc = function_exists('imagecreatefrompng') ? 'imagecreatefrompng' : '';
                self::$imagefunc = function_exists('imagepng') ? 'imagepng' : '';
                break;
        }//为空则匹配类型的函数不存在

        self::$attach['size'] = empty(self::$attach['size']) ? @filesize($srcFile) : self::$attach['size'];
        return self::watermark_gd(0);
    }


    /**
     *  会对空白地方填充满
     * @param string $srcFile 图片路径
     * @param string $toW 转换到的宽度
     * @param string $toH 转换到的高度
     * @param string $toFile 输出文件到
     * @param string $issave 是否保存
     * @return    bool
     */

    private static function resizeNew($srcFile, $toW, $toH)
    {
        self::init();
        $toFile = $srcFile;
        $info = '';
        $srcInfo = getimagesize($srcFile, $info);
        switch ($srcInfo[2]) {
            case 1:
                if (!self::$cfg['photo_type']['gif']) return FALSE;
                $img = imagecreatefromgif($srcFile);
                break;
            case 2:
                if (!self::$cfg['photo_type']['jpeg']) return FALSE;
                $img = imagecreatefromjpeg($srcFile);
                break;
            case 3:
                if (!self::$cfg['photo_type']['png']) return FALSE;
                $img = imagecreatefrompng($srcFile);
                break;
            case 6:
                if (!self::$cfg['photo_type']['bmp']) return FALSE;
                $img = imagecreatefromwbmp($srcFile);
                break;
        }

        $width = $img ? imagesx($img) : 0;
        $height = $img ? imagesy($img) : 0;

        if (!$width || !$height) {
            return FALSE;
        }

        $target_width = $toW;
        $target_height = $toH;
        $target_ratio = $target_width / $target_height;

        $img_ratio = $width / $height;

        if ($target_ratio > $img_ratio) {
            $new_height = $target_height;
            $new_width = $img_ratio * $target_height;
        } else {
            $new_height = $target_width / $img_ratio;
            $new_width = $target_width;
        }

        if ($new_height > $target_height) {
            $new_height = $target_height;
        }
        if ($new_width > $target_width) {
            $new_height = $target_width;
        }

        $new_img = ImageCreateTrueColor($target_width, $target_height);

        $bgcolor = self::$config['imgBgcolor'] == 0 ? ImageColorAllocate($new_img, 0xff, 0xff, 0xff) : 0;

        if (!@imagefilledrectangle($new_img, 0, 0, $target_width - 1, $target_height - 1, $bgcolor)) {
            return FALSE;
        }

        if (!@imagecopyresampled($new_img, $img, ($target_width - $new_width) / 2, ($target_height - $new_height) / 2, 0, 0, $new_width, $new_height, $width, $height)) {
            return FALSE;
        }

        switch ($srcInfo[2]) {
            case 1:
                imagegif($new_img, $toFile);
                break;
            case 2:
                imagejpeg($new_img, $toFile, 100);
                break;
            case 3:
                imagepng($new_img, $toFile);
                break;
            case 6:
                imagebmp($new_img, $toFile);
                break;
            default:
                return FALSE;
        }
        imagedestroy($new_img);
        imagedestroy($img);
        return TRUE;
    }
}
