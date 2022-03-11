<?php

/**
 * 压缩、解压zip类
 * @author zhucy
 * @date 2022.03.11
 */

namespace SlimCMS\Helper;

class Zip
{
    public static function pack(array $files)
    {
        $zip = new \ZipArchive();
        $zipname = uniqid() . '.zip';

        if ($zip->open($zipname, \ZipArchive::CREATE) !== TRUE) {
            return false;
        }
        foreach ($files as $key => $v) {
            $v = trim($v,'/');
            $file = CSPUBLIC . $v;
            $file_type = pathinfo($file, PATHINFO_EXTENSION);  //文件类型，取后缀
            $file_basename = basename($file);
            if (is_file($file)) {
                $zip->addFile($v);
            } else {
                $zip->addFile($file_basename . '_' . '(File_Miss)' . '.' . $file_type);
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
        return true;
    }

    /**
     * 解压
     * @param string $file
     * @return bool
     */
    public static function unpack(string $file): bool
    {
        if (empty($file)) {
            return false;
        }
        $zip = new \ZipArchive;
        if ($zip->open($file) === TRUE) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (is_dir(CSPUBLIC . $filename)) {
                    File::mkdir(CSPUBLIC . $filename);
                } else {
                    $s = $zip->getStream($filename);
                    $data = stream_get_contents($s);
                    file_put_contents(CSPUBLIC . $filename, $data);
                }
            }
            $zip->close();
            return true;
        }
        $zip->close();
        return false;
    }
}
