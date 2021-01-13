<?php
declare(strict_types=1);

namespace SlimCMS\Interfaces;

interface UploadInterface
{
    /**
     * 图片H5上传数据处理
     * @param string $img
     * @return OutputInterface
     */
    public function h5(string $img): OutputInterface;

    /**
     * 上传附件
     * @param array $post
     * @return OutputInterface
     */
    public function upload(array $post): OutputInterface;

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

}
