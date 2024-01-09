<?php

/**
 * 压缩、解压zip工具类
 * @author zhucy
 * @date 2022.03.11
 */

namespace SlimCMS\Helper;

class Zip
{
    /**
     * 压缩
     * @param array $files ,例：[['file'=>'xxx.php','name'=>'文件名1'],['file'=>'uploads/2022/测试.doc','name'=>'文件名2']]
     * @param bool $inroot 是否将文件打包在同一根目录下
     * @return bool
     */
    public static function pack(array $files, bool $inroot = true): bool
    {
        $zip = new \ZipArchive();
        $dir = CSPUBLIC . 'uploads/tmp/' . date('Y') . '/';
        File::mkdir($dir);
        $zipname = $dir . uniqid() . '.zip';

        if ($zip->open($zipname, \ZipArchive::CREATE) !== TRUE) {
            return false;
        }
        $i = 0;
        $tmpFiles = [];
        foreach ($files as $v) {
            $i++;
            $v['file'] = trim($v['file'], '/');
            $file = CSPUBLIC . $v['file'];
            $fileType = pathinfo($file, PATHINFO_EXTENSION);  //文件类型，取后缀
            $fileBasename = basename($file);
            if (is_file($file)) {
                if ($inroot === true) {
                    if (!empty($v['name'])) {
                        $v['name'] = preg_match_all('/[\x{4e00}-\x{9fff}\w\(\)-]+/u', $v['name'], $matches);
                        $v['name'] = join('', $matches[0]);
                    }
                    $name = !empty($v['name']) ? $v['name'] : $fileBasename;
                    $name = $i . '_' . $name . '.' . $fileType;
                    $tmpFiles[] = $name;
                    $zip->addFromString($name, file_get_contents($file));
                } else {
                    $zip->addFile($v['file']);
                }
            } else {
                $zip->addFile($fileBasename . '_' . '(File_Miss)' . '.' . $fileType);
            }
        }

        $zip->close();
        header('Pragma: public'); // required
        header('Expires: 0'); // no cache
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Cache-Control: private', false);
        header('Content-Type: application/force-download');//强制下载
        header('Content-Disposition: attachment; filename="' . basename($zipname) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Connection: close');
        readfile($zipname); // push it out

        //每一次下载后删除旧压缩包
        if (is_file($zipname)) {
            @unlink($zipname);
        }
        //删除临时复制的文件
        foreach ($tmpFiles as $v) {
            @unlink($dir . $v);
        }
        return true;
    }

    /**
     * 解压
     * @param string $file 压缩包文件地址，只能为zip格式
     * @param string $path 解压到目录
     * @return bool
     */
    public static function unpack(string $file, string $path = null): bool
    {
        if (empty($file)) {
            return false;
        }
        $path = $path ?: CSPUBLIC;
        $path = rtrim($path, '/') . '/';
        $zip = new \ZipArchive;
        if ($zip->open($file) === TRUE) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (substr($filename, -1) == '/') {
                    File::mkdir($path . $filename, 0777, false);
                } else {
                    $s = $zip->getStream($filename);
                    $data = stream_get_contents($s);
                    file_put_contents($path . $filename, $data);
                }
            }
            $zip->close();
            return true;
        }
        $zip->close();
        return false;
    }
}
