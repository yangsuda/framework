<?php
/**
 * 图片处理类
 * @author zhucy
 */

namespace SlimCMS\Core;

use SlimCMS\Abstracts\BaseAbstract;
use SlimCMS\Interfaces\UploadInterface;

class Image extends BaseAbstract
{
    private $attachinfo;
    private $targetfile;    //图片路径
    private $imagecreatefromfunc;
    private $imagefunc;
    private $attach;
    private $cfg = [];

    private function init()
    {
        //检测用户系统支持的图片格式
        $this->cfg['photo_type']['gif'] = FALSE;
        $this->cfg['photo_type']['jpeg'] = FALSE;
        $this->cfg['photo_type']['png'] = FALSE;
        $this->cfg['photo_type']['wbmp'] = FALSE;
        $this->cfg['photo_typenames'] = [];
        $this->cfg['photo_support'] = '';
        if (function_exists("imagecreatefromgif") && function_exists("imagegif")) {
            $this->cfg['photo_type']["gif"] = TRUE;
            $this->cfg['photo_typenames'][] = "image/gif";
            $this->cfg['photo_support'] .= "GIF ";
        }
        if (function_exists("imagecreatefromjpeg") && function_exists("imagejpeg")) {
            $this->cfg['photo_type']["jpeg"] = TRUE;
            $this->cfg['photo_typenames'][] = "image/pjpeg";
            $this->cfg['photo_typenames'][] = "image/jpeg";
            $this->cfg['photo_support'] .= "JPEG ";
        }
        if (function_exists("imagecreatefrompng") && function_exists("imagepng")) {
            $this->cfg['photo_type']["png"] = TRUE;
            $this->cfg['photo_typenames'][] = "image/png";
            $this->cfg['photo_typenames'][] = "image/xpng";
            $this->cfg['photo_support'] .= "PNG ";
        }
        if (function_exists("imagecreatefromwbmp") && function_exists("imagewbmp")) {
            $this->cfg['photo_type']["wbmp"] = TRUE;
            $this->cfg['photo_typenames'][] = "image/wbmp";
            $this->cfg['photo_support'] .= "WBMP ";
        }
    }

    private function watermark_gd($preview = 0)
    {
        if (function_exists('imagecopy') && function_exists('imagealphablending') && function_exists('imagecopymerge')) {
            $imagecreatefunc = $this->imagecreatefromfunc;
            $imagefunc = $this->imagefunc;
            list($imagewidth, $imageheight) = $this->attachinfo;
            if (empty($this->config['markimg'])) {
                return false;
            }
            $watermark_file = CSPUBLIC . $this->config['markimg'];
            $watermarkinfo = @getimagesize($watermark_file);
            $watermark_logo = @imagecreatefrompng($watermark_file);
            if (!$watermark_logo) {
                return false;
            }
            list($logowidth, $logoheight) = $watermarkinfo;
            $wmwidth = $imagewidth - $logowidth;
            $wmheight = $imageheight - $logoheight;
            if (is_readable($watermark_file) && $wmwidth > 10 && $wmheight > 10) {
                if ($this->config['waterpos'] == 0) {
                    $this->config['waterpos'] = mt_rand(1, 9);
                }
                $x = $y = 0;
                switch ($this->config['waterpos']) {
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
                if ($this->attachinfo[2] == 3) {
                    imagealphablending($dst_photo, false);//意思是不合并颜色,直接用图像颜色替换,包括透明色;
                    imagesavealpha($dst_photo, true);//意思是不要丢了图像的透明色;
                }
                $target_photo = $imagecreatefunc($this->targetfile);
                $this->attachinfo[2] == 3 && imagesavealpha($target_photo, true);//意思是不要丢了图像的透明色;
                imagecopy($dst_photo, $target_photo, 0, 0, 0, 0, $imagewidth, $imageheight);
                imagecopy($dst_photo, $watermark_logo, $x, $y, 0, 0, $logowidth, $logoheight);
                $targetfile = !$preview ? $this->targetfile : './watermark_tmp.jpg';
                if ($this->attachinfo['mime'] == 'image/jpeg') {
                    $imagefunc($dst_photo, $targetfile, 100);
                } else {
                    $imagefunc($dst_photo, $targetfile);
                }
                $this->attach['size'] = filesize($this->targetfile);
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
    public function imageResize($file, $width = 0, $height = 0)
    {
        $width = $width ?: $this->config['imgWidth'];
        $height = $height ?: $this->config['imgHeight'];
        if ($this->config['imgFull'] == '1') {
            $this->resizeNew($file, $width, $height);
        } else {
            $this->resize($file, $width, $height);
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
    public function resize($srcFile, $toW, $toH)
    {
        $this->init();
        $toFile = $srcFile;
        $info = '';
        $srcInfo = getimagesize($srcFile, $info);
        switch ($srcInfo[2]) {
            case 1:
                if (!$this->cfg['photo_type']['gif']) return FALSE;
                $im = imagecreatefromgif($srcFile);
                break;
            case 2:
                if (!$this->cfg['photo_type']['jpeg']) return FALSE;
                $im = imagecreatefromjpeg($srcFile);
                break;
            case 3:
                if (!$this->cfg['photo_type']['png']) return FALSE;
                $im = @imagecreatefrompng($srcFile);
                imagesavealpha($im, true);//意思是不要丢了图像的透明色;
                break;
            case 6:
                if (!$this->cfg['photo_type']['bmp']) return FALSE;
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
                    $jpgQuality = aval($this->config, 'jpgQuality', 95);
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
    public function waterImg($srcFile)
    {
        if (empty($this->config['markimg']) || !is_file(CSPUBLIC . $this->config['markimg'])) {
            return false;
        }
        $this->targetfile = $srcFile;
        $this->attachinfo = @getimagesize($srcFile);
        if ($this->attachinfo['mime'] == 'image/gif') {
            return false;
        }
        $markimgInfo = @getimagesize(CSPUBLIC . $this->config['markimg']);
        if ($this->attachinfo[0] <= $markimgInfo[0] && $this->attachinfo[1] <= $markimgInfo[1]) {
            return false;
        }

        switch ($this->attachinfo['mime']) {
            case 'image/jpeg':
                $this->imagecreatefromfunc = function_exists('imagecreatefromjpeg') ? 'imagecreatefromjpeg' : '';
                $this->imagefunc = function_exists('imagejpeg') ? 'imagejpeg' : '';
                break;
            case 'image/png':
                $this->imagecreatefromfunc = function_exists('imagecreatefrompng') ? 'imagecreatefrompng' : '';
                $this->imagefunc = function_exists('imagepng') ? 'imagepng' : '';
                break;
        }//为空则匹配类型的函数不存在

        $this->attach['size'] = empty($this->attach['size']) ? @filesize($srcFile) : $this->attach['size'];
        return $this->watermark_gd(0);
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

    private function resizeNew($srcFile, $toW, $toH)
    {
        $this->init();
        $toFile = $srcFile;
        $info = '';
        $srcInfo = getimagesize($srcFile, $info);
        switch ($srcInfo[2]) {
            case 1:
                if (!$this->cfg['photo_type']['gif']) return FALSE;
                $img = imagecreatefromgif($srcFile);
                break;
            case 2:
                if (!$this->cfg['photo_type']['jpeg']) return FALSE;
                $img = imagecreatefromjpeg($srcFile);
                break;
            case 3:
                if (!$this->cfg['photo_type']['png']) return FALSE;
                $img = @imagecreatefrompng($srcFile);
                break;
            case 6:
                if (!$this->cfg['photo_type']['bmp']) return FALSE;
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

        $bgcolor = $this->config['imgBgcolor'] == 0 ? ImageColorAllocate($new_img, 0xff, 0xff, 0xff) : 0;

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
