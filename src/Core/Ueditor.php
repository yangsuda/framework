<?php

/**
 * ueditor类
 * @author zhucy
 */

namespace SlimCMS\Core;

use App\Core\Upload;
use SlimCMS\Abstracts\ModelAbstract;
use SlimCMS\Interfaces\OutputInterface;
use SlimCMS\Interfaces\UploadInterface;

class Ueditor extends ModelAbstract
{

    private static $uconfig = [];

    public static function config(): OutputInterface
    {
        if (!self::$uconfig) {
            $data = file_get_contents(CSPUBLIC . 'ueditor/config.json');
            $data = preg_replace("/\/\*[\s\S]+?\*\//", "", $data);
            self::$uconfig = json_decode($data, true);
        }
        return self::$output->withCode(200)->withData(self::$uconfig);
    }

    /**
     * 文件上传
     * @param string $fieldName
     * @param string $type
     * @param bool $water
     * @return OutputInterface
     */
    public static function upload(string $fieldName, string $type = 'image', bool $water = false): OutputInterface
    {
        $uconfig = self::config()->getData();
        if ($fieldName == 'scrawlFieldName') {
            $uploadData = 'data:image/jpeg;base64,' . $_POST[$uconfig[$fieldName]];
        } else {
            if(empty($_FILES[$uconfig[$fieldName]])){
                return self::$output->withCode(23001);
            }
            $uploadData = ['files' => $_FILES[$uconfig[$fieldName]], 'width' => self::$config['imgWidth'], 'height' => self::$config['imgHeight'], 'water' => $water, 'type' => $type];
        }
        $upload = self::$container->get(UploadInterface::class);
        if (is_string($uploadData)) {
            $res = $upload->h5($uploadData);
        } else {
            $res = $upload->upload($uploadData);
        }
        $result = [];
        if ($res->getCode() != 200 && $res->getCode() != 23001) {
            $result['state'] = $res->getMsg();
        } else {
            $data = $res->getData();
            if(!empty($data)){
                $result['state'] = 'SUCCESS';
                $result['url'] = trim(self::$config['attachmentHost'], '/') . $data['fileurl'];
                $result['title'] = basename($data['fileurl']);
                $result['original'] = '';
                $result['type'] = pathinfo($data['fileurl'], PATHINFO_EXTENSION);
                $info = $upload->metaInfo($data['fileurl'])->getData();
                $result['size'] = aval($info,'size');
            }
        }
        return self::$output->withCode(200)->withData($result);
    }

    /**
     * 列出图片/文件
     */
    public static function listData(int $size = 20, int $start = 0): OutputInterface
    {
        $uconfig = self::config()->getData();
        $listSize = $uconfig['fileManagerListSize'];
        /* 获取参数 */
        $size = $size ?: $listSize;
        $end = $start + $size;

        /* 获取文件列表 */
        $files = self::getFiles();
        if (!count($files)) {
            return ["state" => "no match file", "list" => [], "start" => $start, "total" => count($files)];
        }

        /* 获取指定范围的列表 */
        $len = count($files);
        for ($i = min($end, $len) - 1, $list = []; $i < $len && $i >= 0 && $i >= $start; $i--) {
            $list[] = $files[$i];
        }
        //倒序
        //for ($i = $end, $list = array(); $i < $len && $i < $end; $i++){
        //    $list[] = $files[$i];
        //}

        $data = ["state" => "SUCCESS", "list" => $list, "start" => $start, "total" => count($files)];
        return self::$output->withCode(200)->withData($data);
    }

    /**
     * 遍历获取目录下的指定类型的文件
     */
    protected static function getFiles(): array
    {
        $where = [];
        $where['isfirst'] = 1;
        $list = self::t('uploads')->withWhere($where)->withLimit(1000)->fetchList('url,mediatype');
        $files = [];
        foreach ($list as $v) {
            $url = ltrim($v['url'], '/');
            if (!is_file(CSPUBLIC . $url)) {
                continue;
            }
            $v['url'] = self::$config['basehost'] . $url;
            $files[] = ['url' => $v['url'], 'mtime' => filemtime(CSPUBLIC . $url)];
        }
        return $files;
    }

    /**
     * 获取ueditor编辑器
     * @param string $content 内容
     * @param string $fieldname 字段名称
     * @param array $config 配置参数
     */
    public static function ueditor(string $fieldname = 'content', string $content = '', array $config = []): OutputInterface
    {
        static $has_load = false;
        $ue = [];
        $identity = aval($config, 'identity');
        if ($identity == 'member') {
            $ue[] = "toolbars: [[
            'simpleupload','bold', 'italic', 'underline', 'removeformat', '|', 'forecolor', 'backcolor', '|',
            'fontfamily', 'fontsize', '|',
            'justifyleft', 'justifycenter', 'justifyright', 'justifyjustify', '|', 
            'link', 'unlink', '|',
            'undo', 'redo','|',
            'inserttable', 'deletetable', 'insertparagraphbeforetable', 'insertrow', 'deleterow', 'insertcol', 
            'deletecol', 'mergecells', 'mergeright', 'mergedown', 'splittocells', 'splittorows', 'splittocols',
			]]";
            $ue[] = 'enableContextMenu: false';
        } elseif ($identity == 'simple') {
            $ue[] = "toolbars: [[
            'fullscreen','undo', 'redo', '|',
            'bold', 'italic', 'underline', 'fontborder', 'strikethrough', 'removeformat', 'formatmatch', 'autotypeset', 'pasteplain', '|', 
            'forecolor', 'backcolor','selectall', 'cleardoc', 'justifyleft', 'justifycenter', 'justifyright', 'justifyjustify', '|',
             'touppercase', 'tolowercase', '|',
            'link', 'unlink','drafts']]";
        } elseif ($identity == 'small') {
            $ue[] = "toolbars: [['undo', 'redo', '|', 
            'bold', 'italic', 'underline', 'fontborder', 'strikethrough', 'removeformat', 'formatmatch', '|',
            'selectall', 'cleardoc', 'justifyleft', 'justifycenter', 'justifyright', 'justifyjustify', '|', 
            'touppercase', 'tolowercase', '|','drafts']]";
        } elseif ($identity == 'admin') {

        }
        if (empty($config['initialFrameHeight'])) {
            $ue[] = 'initialFrameHeight:350';
        }
        $ue[] = 'catchRemoteImageEnable:false';
        foreach ($config as $k => $v) {
            $ue[] = $k . ':\'' . $v . '\'';
        }
        if (empty($config['serverUrl'])) {
            $ue[] = 'serverUrl:"' . self::$config['basehost'] . '/admin/ueditor"';
        }
        $ue[] = 'csrf_token:"'.\App\Core\Csrf::getToken().'"';
        $ue[] = 'pageBreakTag:"#p#副标题#e#"';
        $data = [];
        $data['fieldname'] = $fieldname;
        $data['content'] = $content;
        $data['has_load'] = $has_load;
        $data['ue'] = implode(',', $ue);
        $result = self::$output->withData($data)->withTemplate('block/fieldshtml/ueditor')->analysisTemplate(true);
        if (empty($has_load)) {
            $has_load = true;
        }
        return self::$output->withCode(200)->withData(['ueditor' => $result]);
    }
}
