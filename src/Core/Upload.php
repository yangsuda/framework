<?php
/**
 * 附件上传类
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core;

use Slim\Psr7\UploadedFile;
use SlimCMS\Abstracts\BaseAbstract;
use SlimCMS\Helper\Ipdata;
use SlimCMS\Helper\File;
use SlimCMS\Interfaces\CookieInterface;
use SlimCMS\Interfaces\OutputInterface;
use SlimCMS\Interfaces\UploadInterface;

class Upload extends BaseAbstract implements UploadInterface
{
    use \SlimCMS\Traits\table;

    /**
     * @param string|null $dirrule
     * @return string
     */
    protected function getSaveDir(string $dirrule = null): string
    {
        $dir = !empty($this->setting['attachment']['dirname']) ? trim($this->setting['attachment']['dirname'], '/') : 'uploads';
        if (!isset($dirrule)) {
            if (!empty($this->setting['attachment']['dirrule'])) {
                $dirrule = str_replace(
                    ['{Y}', '{m}', '{d}'],
                    [date('Y'), date('m'), date('d')],
                    trim($this->setting['attachment']['dirrule'], '/'));
            } else {
                $dirrule = date('Y/m');
            }
        }
        return $dir . '/' . ($dirrule ? $dirrule . '/' : '');
    }

    /**
     * @inheritDoc
     */
    public function h5(string $str): OutputInterface
    {
        if (preg_match('/^data:\s*([^\/]+)\/([^\/]+);base64,/', $str, $matches)) {
            $str = preg_replace('/^data:image\/\w+;base64,/', '', $str);
            $data = base64_decode($str);
            if (empty($data)) {
                return self::$output->withCode(27013);
            }

            //防止伪装成图片的木马上传
            $checkWords = aval($this->setting, 'security/uploadCheckWords');
            if (!empty($checkWords) && preg_match('/(' . $checkWords . ')/i', $data)) {
                return self::$output->withCode(23005);
            }

            $dirname = $this->getSaveDir('tmp');
            $file = uniqid() . '.' . $matches[2];
            $tmpPath = CSPUBLIC . $dirname;
            File::mkdir($tmpPath);
            $fileUrl = $tmpPath . $file;
            $success = file_put_contents($fileUrl, $data);
            if (!$success) {
                return self::$output->withCode(23014);
            }

            if (in_array($matches[2], explode('|', $this->config['mediatype']))) {
                $types = 'media';
            } elseif (in_array($matches[2], explode('|', $this->config['imgtype']))) {
                $types = 'image';
            } else {
                $types = 'addon';
            }
            $mimeType = $matches[1] . '/' . $matches[2]; // 提取 MIME 类型
            $uploadFile = new UploadedFile($fileUrl, $file, $mimeType, filesize($fileUrl));
            return $this->upload($uploadFile, $types);
        }
        return $this->output->withCode(27013);
    }

    /**
     * @inheritDoc
     */
    public function upload(UploadedFile $post, string $type = 'image', string $dir = null): OutputInterface
    {
        if ($post->getSize() < 1) {
            return $this->output->withCode(23001);
        }

        $dirname = $this->getSaveDir($dir);
        $imgdir = CSPUBLIC . $dirname;
        File::mkdir($imgdir);

        $not_allow = aval($this->setting, 'security/uploadForbidFile', 'php|pl|cgi|asp|aspx|jsp|php3|shtm|shtml|js');
        $file_name = trim(preg_replace("#[ \r\n\t\*\%\\\/\?><\|\":]{1,}#", '', $post->getClientFilename()));
        if (!empty($file_name) && (preg_match("#\.(" . $not_allow . ")$#i", $file_name) || strpos($file_name, '.') === false)) {
            @unlink($post->getFilePath());
            return $this->output->withCode(23004);
        }

        //防止伪装成图片的木马上传
        $checkWords = aval($this->setting, 'security/uploadCheckWords');
        if (!empty($checkWords) && preg_match('/(' . $checkWords . ')/i', file_get_contents($post->getFilePath()))) {
            @unlink($post->getFilePath());
            return $this->output->withCode(23005);
        }
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        //源文件类型检查
        $code = '';
        switch ($type) {
            case 'image':
                if (strpos($this->config['imgtype'], $ext) === false) {
                    $code = 23006;
                    break;
                }
                if ($post->getFilePath() != 'php://temp') {
                    $info = getimagesize($post->getFilePath());
                    //检测文件类型
                    if (!is_array($info) || !in_array($info[2], [1, 2, 3, 6])) {
                        $code = 23001;
                    }
                }
                break;
            case 'flash':
                if ($ext != 'swf') {
                    $code = 23007;
                }
                break;
            case 'media':
                if (strpos($this->config['mediatype'], $ext) === false) {
                    $code = 23008;
                }
                break;
            case 'addon':
                $subject = $this->config['imgtype'] . '|' . $this->config['mediatype'] . '|' . $this->config['softtype'];
                $allAllowType = str_replace('||', '|', $subject);
                if (strpos($allAllowType, $ext) === false) {
                    $code = 23009;
                }
                break;
            default:
                $code = 23010;
        }
        if ($post->getSize() > $this->config['maxUploadSize'] * 1024) {
            $code = 23012;
        }
        if ($code) {
            @unlink($post->getFilePath());
            return $this->output->withCode($code);
        }

        $filename = $imgdir . str_replace('.', '', uniqid(substr(md5(Ipdata::getip()), 20), true)) . '.' . $ext;
        $post->moveTo($filename);
        $fileurl = str_replace(CSPUBLIC, '/', $filename);
        //保存信息到数据库
        $this->save($fileurl, 1);
        return $this->output->withCode(200)->withData(['fileurl' => $fileurl]);
    }

    /**
     * URL入库
     * @param string $url
     * @param int $isfirst
     * @return int
     */
    protected function save(string $url, int $isfirst = 2): int
    {
        $dirname = $this->getSaveDir('');
        $data = [];
        $data['url'] = preg_replace("'(.*)?(/" . rtrim($dirname, '/') . "/(.*)){1}'isU", "\\2", $url);
        $p = pathinfo($url);
        if (preg_match("/jpg|jpeg|gif|png/i", $p['extension'])) {
            $data['mediatype'] = 1;
        } elseif ($p['extension'] == 'swf') {
            $data['mediatype'] = 2;
        } elseif (preg_match("/mp4|rmvb|rm|wmv|flv|mpg|avi|mpeg|mov|ram|3gp|asf|rmv/i", $p['extension'])) {
            $data['mediatype'] = 3;
        } elseif (preg_match("/wav|mp3|wma|mov|amr|mid|ape|wv|aac|flac|alac/i", $p['extension'])) {
            $data['mediatype'] = 4;
        } elseif (preg_match("/zip|gz|rar|tar|7z|jar|cab|arj|ace/i", $p['extension'])) {
            $data['mediatype'] = 5;
        } else {
            $data['mediatype'] = 6;
        }
        $file = CSPUBLIC . rtrim($url, '/');
        if ($data['mediatype'] == 1) {
            $p = @getimagesize($file);
            $data['width'] = $p[0];
            $data['height'] = $p[1];
        }
        is_file($file) && $data['filesize'] = @filesize($file);
        $data['isfirst'] = $isfirst == 1 ? 1 : 2;
        $data['createtime'] = TIMESTAMP;
        $data['ip'] = Ipdata::getip();
        return $this->t('uploads')->insert($data, true);
    }

    /**
     * @inheritDoc
     */
    public function webupload(UploadedFile $file, array $option = []): OutputInterface
    {
        if (empty($file)) {
            return $this->output->withCode(23001);
        }
        $session = $this->session();
        if ($session->has('bigfile_info') && count($session->get('bigfile_info')) >= 10) {
            return $this->output->withCode(23002);
        }
        $result = $this->upload($file, 'image');
        if ($result->getCode() != 200) {
            return $result;
        }
        $fileurl = $result->getData()['fileurl'];

        //加水印或缩小图片
        $this->i(Image::class)->imageResize(CSPUBLIC . $fileurl, aval($option, 'width'), aval($option, 'height'));
        (!empty($option['water']) || !empty($this->config['waterMark'])) && $this->i(Image::class)->waterImg(CSPUBLIC . $fileurl);

        //保存信息到 session
        $bigfile_info = $session->get('bigfile_info');
        $bigfile_info[$option['fileid']] = $fileurl;
        $session->set('bigfile_info', $bigfile_info);
        $session->set('fileid', $option['fileid']);
        $data = ['fileid' => $option['fileid'], 'imgurl' => $this->copyImage($fileurl, 120, 120)];
        return $this->output->withCode(200)->withData($data);
    }

    /**
     * @inheritDoc
     */
    public function getWebupload(): OutputInterface
    {
        $imgurls = [];
        $session = $this->session();
        if ($session->has('bigfile_info')) {
            if (count($session->get('bigfile_info')) > 10) {
                foreach ($session->get('bigfile_info') as $_v) {
                    $this->uploadDel($_v['img']);
                }
                return $this->output->withCode(21045);
            }
            if (is_array($session->get('bigfile_info'))) {
                foreach ($session->get('bigfile_info') as $_k => $_v) {
                    if ($imginfos = getimagesize(CSPUBLIC . ltrim($_v, '/'))) {
                        $key = md5($_v);
                        $imgurls[$key]['img'] = $_v;
                        $imgurls[$key]['text'] = $this->i(Request::class)->input('picinfook' . $_k);
                        $imgurls[$key]['width'] = $imginfos[0];
                        $imgurls[$key]['height'] = $imginfos[1];
                    }
                }
            }
        }
        $session->delete('bigfile_info');
        return $this->output->withCode(200)->withData($imgurls);
    }

    /**
     * @inheritDoc
     */
    public function uploadDel(string $url): OutputInterface
    {
        if (empty($url)) {
            return $this->output->withCode(21002);
        }
        if ($pics = $this->listByUrl($url)) {
            $ids = [];
            foreach ($pics as $v) {
                $ids[] = $v['id'];
                $upfile = strpos($url, CSPUBLIC) === false ? CSPUBLIC . ltrim($v['url'], '/') : $v['url'];
                $upfile = realpath($upfile);
                if ($upfile && @is_file($upfile) && strpos($url, 'nopic') === false) {
                    @unlink($upfile);
                }
            }
            $this->t('uploads')->withWhere(['id' => $ids])->delete();
        }
        return $this->output->withCode(200);
    }

    /**
     * 获取某附件及相关附件
     * @param string $url
     * @return array
     * @throws \SlimCMS\Error\TextException
     */
    protected function listByUrl(string $url): array
    {
        $url = str_replace($this->config['basehost'], '', $url);
        $ext = $this->config['imgtype'] . '|' . $this->config['softtype'] . '|' . $this->config['mediatype'];
        if (empty($url) || preg_match('#http:\/\/#i', $url) || !preg_match('#\.(' . $ext . ')#', $url)) {
            return [];
        }
        if (strpos($url, '_')) {
            $url = preg_replace('#(.*)(_)?(\d+)?(x)?(\d+)?(\.(' . $this->config['imgtype'] . ')){1}#isU', '\\1', $url);
        } else {
            $url = pathinfo($url, PATHINFO_DIRNAME) . '/' . pathinfo($url, PATHINFO_FILENAME);
        }
        $url = str_replace("'", '', $url);
        $where = [];
        $where[] = $this->t('uploads')->field('url', $url . '%', 'like');
        return $this->t('uploads')->withWhere($where)->fetchList();
    }

    /**
     * @inheritDoc
     */
    public function metaInfo(string $url, string $info = 'url,size'): OutputInterface
    {
        if (empty($url)) {
            return $this->output->withCode(21002);
        }
        $data = [];
        $arr = explode(',', $info);
        if (in_array('url', $arr)) {
            $data['url'] = trim($this->config['attachmentHost'], '/') . $url;
        }
        if (in_array('size', $arr)) {
            $data['size'] = filesize(CSPUBLIC . $url);
        }
        if (in_array('width', $arr) || in_array('height', $arr)) {
            $info = getimagesize(CSPUBLIC . $url);
            $data['width'] = $info[0];
            $data['height'] = $info[1];
        }
        return $this->output->withCode(200)->withData($data);
    }

    /**
     * @inheritDoc
     */
    public function copyImage(string $pic = null, int $width = 2000, int $height = 2000, $more = []): string
    {
        $nopic = aval($more, 'nopic', 'resources/global/images/nopic/nopic.jpg');
        if (empty($pic)) {
            return $nopic;
        }
        $attachmentHost = !empty($this->config['attachmentHost']) ? $this->config['attachmentHost'] : $this->config['basehost'];
        $attachmentHost = rtrim($attachmentHost, '/') . '/';
        if (preg_match('/' . $this->config['domain'] . '/i', $pic)) {
            $pic = str_replace(rtrim($this->config['basehost'], '/'), '', $pic);
        }
        if (preg_match("/^(https?:\/\/)/i", $pic)) {
            return $pic;
        }
        $ext = pathinfo($pic, PATHINFO_EXTENSION);
        if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'png'])) {
            return rtrim($this->config['basehost'], '/') . $pic;
        }

        $pic = ltrim($pic, '/');
        $oldurl = CSPUBLIC . $pic;
        $ptype = (string)strrchr($pic, '.');
        //如果有已经生成的图片直接返回
        $newpic = str_replace($ptype, "_{$width}x{$height}" . $ptype, $pic);
        if (is_file(CSPUBLIC . $newpic)) {
            return $attachmentHost . $newpic;
        }
        $imgdata = is_file($oldurl) ? @getimagesize($oldurl) : [];
        if (!$imgdata) {
            $pic = $nopic;
            $oldurl = CSPUBLIC . $pic;
            $ptype = strrchr($pic, '.');
            $imgdata = @getimagesize($oldurl);
        }
        if ($imgdata[0] > $width || $imgdata[1] > $height) {
            $newpic = str_replace($ptype, "_{$width}x{$height}" . $ptype, $pic);
            $newurl = CSPUBLIC . $newpic;
            if (is_file($newurl)) {
                return $attachmentHost . $newpic;
            }
            if (@copy($oldurl, $newurl) && is_file($newurl) && $this->i(Image::class)->resize($newurl, $width, $height)) {
                $this->save('/' . $newpic);
            }
            return $attachmentHost . $newpic;
        }
        return $attachmentHost . $pic;
    }

    /**
     * @inheritDoc
     */
    public function superFileUpload(UploadedFile $file, int $index, string $filename, string $diyDir = ''): OutputInterface
    {
        if (empty($file) || empty($index) || empty($filename)) {
            return $this->output->withCode(21002);
        }

        $not_allow = aval($this->setting, 'security/uploadForbidFile', 'php|pl|cgi|asp|aspx|jsp|php3|shtm|shtml|js');
        if (preg_match("#\.(" . $not_allow . ")$#i", $filename)) {
            @unlink($file->getFilePath());
            return $this->output->withCode(23004);
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $subject = $this->config['imgtype'] . '|' . $this->config['mediatype'] . '|' . $this->config['softtype'];
        $allAllowType = str_replace('||', '|', $subject);
        if (strpos($allAllowType, $ext) === false) {
            return $this->output->withCode(23009);
        }

        $cachekey = __FUNCTION__;
        $cookie = $this->container->get(CookieInterface::class);
        if ($index == 1) {
            $cookie->set($cachekey, md5((string)microtime(true) . mt_rand(1000, 9999)), 3600);
        }

        $md5filename = $cookie->get($cachekey) ?: md5($filename);
        $dir = $this->getSaveDir($diyDir);
        File::mkdir(CSPUBLIC . $dir);
        $path = CSPUBLIC . $dir . $md5filename . '_' . $index;
        $json = [];
        if (!move_uploaded_file($file->getFilePath(), $path)) {
            $json['src'] = $file;
        } else {
            $fileurl = $dir . $md5filename . '.' . $ext;
            //合并文件file_put_contents，file_get_contents两个函数
            file_put_contents(CSPUBLIC . $fileurl, file_get_contents($path), FILE_APPEND);
            unlink($path);//删除合并过的文件
            $json['fileurl'] = '/' . $fileurl;

            //保存信息到数据库
            if ($index == 1) {
                $this->save('/' . $fileurl, 1);
            }
        }
        return $this->output->withCode(200)->withData($json);
    }
}
