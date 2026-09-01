<?php
/**
 * 图集序列化工具
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use SlimCMS\Helper\Str;

/**
 * 图集（imgs 字段）序列化/反序列化
 *
 * 替换原 Forms::serializeImgs / unserializeImgs，无状态。
 */
final class ImgsSerializer
{
    /**
     * 序列化图集
     *
     * @param array $imgs [['url'=>'...','text'=>'...'], ...]
     */
    public static function serialize(array $imgs): string
    {
        if (empty($imgs)) {
            return '';
        }
        $imgurls = [];
        foreach ($imgs as $v) {
            if (!empty($v['url'])) {
                $key = md5($v['url']);
                $imgurls[$key]['img'] = Str::htmlspecialchars($v['url']);
                $imgurls[$key]['text'] = !empty($v['text']) ? Str::htmlspecialchars($v['text']) : '';
            }
        }
        return $imgurls ? json_encode($imgurls) : '';
    }

    /**
     * 反序列化图集
     *
     * @param string $imgs JSON
     * @param int $width 缩略图宽
     * @param int $height 缩略图高
     * @param \Closure|null $copyImageFn 自定义缩略图生成函数 fn($img,$w,$h)，避免在此工具里注入 uploader
     */
    public static function unserialize(string $imgs, int $width = 1000, int $height = 1000, ?\Closure $copyImageFn = null): array
    {
        if (empty($imgs)) {
            return [];
        }
        $data = array_values(json_decode($imgs, true) ?: []);
        foreach ($data as &$v1) {
            $v1['originImg'] = $v1['img'];
            if ($copyImageFn) {
                $v1['img'] = $copyImageFn($v1['img'], $width, $height);
            }
        }
        return $data;
    }
}
