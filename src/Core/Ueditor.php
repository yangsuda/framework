<?php

/**
 * ueditor类
 * @author zhucy
 */

namespace SlimCMS\Core;

use Slim\App;
use SlimCMS\Abstracts\BaseAbstract;
use SlimCMS\Interfaces\OutputInterface;
use SlimCMS\Interfaces\UploadInterface;

class Ueditor extends BaseAbstract
{
    use \SlimCMS\Traits\Table;

    private $uconfig = [];

    private array $config;//后台配置参数
    private OutputInterface $output;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->config = $this->container->get('cfg');
        $this->output = $this->container->get(OutputInterface::class)($app);
    }

    public function config(): OutputInterface
    {
        if (!$this->uconfig) {
            $data = file_get_contents(CSPUBLIC . 'ueditor/config.json');
            $data = preg_replace("/\/\*[\s\S]+?\*\//", "", $data);
            $this->uconfig = json_decode($data, true);
        }
        return $this->output->withCode(200)->withData($this->uconfig);
    }

    /**
     * 文件上传
     * @param string $fieldName
     * @param string $type
     * @param bool $water
     * @return OutputInterface
     */
    public function upload(string $fieldName, string $type = 'image', bool $water = false): OutputInterface
    {
        $uconfig = $this->config()->getData();
        if ($fieldName == 'scrawlFieldName') {
            $uploadData = 'data:image/jpeg;base64,' . $_POST[$uconfig[$fieldName]];
        } else {
            $file = $this->request->getUploadedFiles();
            $uploadData = $file[$uconfig[$fieldName]] ?? null;
        }
        if (empty($uploadData)) {
            return $this->output->withCode(27013);
        }
        $upload = $this->container->get(UploadInterface::class);
        $res = is_string($uploadData) ? $upload->h5($uploadData) : $upload->upload($uploadData, $type);
        $result = [];
        if ($res->getCode() != 200 && $res->getCode() != 23001) {
            $result['state'] = $res->getMsg();
        } else {
            $data = $res->getData();
            if (!empty($data)) {
                $this->i(Image::class)->imageResize(CSPUBLIC . $data['fileurl']);
                //加水印或缩小图片
                if ($water === true) {
                    $this->i(Image::class)->waterImg(CSPUBLIC . $data['fileurl']);
                }

                $result['state'] = 'SUCCESS';
                $result['url'] = trim($this->config['attachmentHost'], '/') . $data['fileurl'];
                $result['title'] = basename($data['fileurl']);
                $result['original'] = '';
                $result['type'] = pathinfo($data['fileurl'], PATHINFO_EXTENSION);
                $info = $upload->metaInfo($data['fileurl'])->getData();
                $result['size'] = aval($info, 'size');
            }
        }
        return $this->output->withCode(200)->withData($result);
    }

    /**
     * 列出图片/文件
     */
    public function listData(int $size = 20, int $start = 0): OutputInterface
    {
        $uconfig = $this->config()->getData();
        $listSize = $uconfig['fileManagerListSize'];
        /* 获取参数 */
        $size = $size ?: $listSize;
        $end = $start + $size;

        /* 获取文件列表 */
        $files = $this->getFiles();
        if (!count($files)) {
            return $this->output->withCode(200)
                ->withData(["state" => "no match file", "list" => [], "start" => $start, "total" => count($files)]);
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
        return $this->output->withCode(200)->withData($data);
    }

    /**
     * 遍历获取目录下的指定类型的文件
     */
    protected function getFiles(): array
    {
        $where = [];
        $where['isfirst'] = 1;
        $list = $this->t('uploads')->withWhere($where)->withLimit(1000)->fetchList('url,mediatype');
        $files = [];
        foreach ($list as $v) {
            $url = ltrim($v['url'], '/');
            if (!is_file(CSPUBLIC . $url)) {
                continue;
            }
            $v['url'] = $this->config['basehost'] . $url;
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
    public function ueditor(string $fieldname = 'content', string $content = '', array $config = []): string
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
            $ue[] = 'serverUrl:"' . $this->config['basehost'] . '/admin/ueditor"';
        }
        $ue[] = 'csrf_token:"' . $this->request->getAttribute('csrfToken') . '"';
        $ue[] = 'pageBreakTag:"#p#副标题#e#"';
        $data = [];
        $data['fieldname'] = $fieldname;
        $data['content'] = $content;
        $data['has_load'] = $has_load;
        $data['ue'] = implode(',', $ue);
        $result = $this->output->withData($data)->withTemplate('block/fieldshtml/ueditor')->analysisTemplate(true);
        if (empty($has_load)) {
            $has_load = true;
        }
        return $result;
    }
}
