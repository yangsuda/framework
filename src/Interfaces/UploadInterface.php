<?php
/**
 * 上传附件接口类
 */

declare(strict_types=1);

namespace SlimCMS\Interfaces;

interface UploadInterface
{
    /**
     * H5上传数据处理
     * @param string $img
     * @return OutputInterface
     */
    public function h5(string $img): OutputInterface;

    /**
     * 上传附件
     * @param $post
     * @return OutputInterface
     */
    public function upload($post): OutputInterface;

    /**
     * webupload上传
     * @param array $post
     * @return OutputInterface
     */
    public function webupload(array $post): OutputInterface;

    /**
     * 获取webupload上传的图片
     * @return OutputInterface
     */
    public function getWebupload(): OutputInterface;

    /**
     * 删除某一上传附件
     * @param string $url
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function uploadDel(string $url): OutputInterface;

    /**
     * 附件信息
     * @param string $url
     * @return OutputInterface
     */
    public function metaInfo(string $url, string $info = 'url,size'): OutputInterface;

    /**
     * 复制指定大小图片
     * @param $pic
     * @param int $width
     * @param int $height
     * @return mixed|string
     */
    public function copyImage(string $pic, int $width = 2000, int $height = 2000, $more = []): string;

    /**
     * 超大文件上传(将大文件切成小文件上传后拼接成大文件)
     * @param array $file 附件
     * @param int $index 分片上传索引ID
     * @param string $filename 自定义文件名称
     * @return OutputInterface
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function superFileUpload(array $file, int $index, string $filename, string $diyDir = ''): OutputInterface;

}
