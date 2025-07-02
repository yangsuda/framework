<?php
/**
 * 附件上传类
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core;

use Slim\Psr7\UploadedFile;
use SlimCMS\Helper\Ipdata;
use SlimCMS\Helper\File;
use SlimCMS\Interfaces\CookieInterface;
use SlimCMS\Interfaces\OutputInterface;
use SlimCMS\Interfaces\UploadInterface;
use SlimCMS\Abstracts\ModelAbstract;

class Upload extends ModelAbstract implements UploadInterface
{

    public function __construct()
    {
    }

    /**
     * @param string|null $dirrule
     * @return string
     */
    protected function getSaveDir(string $dirrule = null): string
    {
        $dir = !empty(self::$setting['attachment']['dirname']) ? trim(self::$setting['attachment']['dirname'], '/') : 'uploads';
        if (!isset($dirrule)) {
            if (!empty(self::$setting['attachment']['dirrule'])) {
                $dirrule = str_replace(
                    ['{Y}', '{m}', '{d}'],
                    [date('Y'), date('m'), date('d')],
                    trim(self::$setting['attachment']['dirrule'], '/'));
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
            $mimeType = $matches[1] . '/' . $matches[2]; // 提取 MIME 类型
            $str = str_replace(['data:' . $mimeType . ';base64,', ' '], ['', '+'], $str);
            $data = base64_decode($str);
            if (empty($data)) {
                return self::$output->withCode(27013);
            }

            //防止伪装成图片的木马上传
            $checkWords = aval(self::$setting, 'security/uploadCheckWords');
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
            $post = [];
            $post['files']['tmp_name'] = $fileUrl;
            $post['files']['name'] = $file;
            $post['files']['type'] = $mimeType;
            if (in_array($matches[2], explode('|', self::$config['mediatype']))) {
                $types = 'media';
            } elseif (in_array($matches[2], explode('|', self::$config['imgtype']))) {
                $types = 'image';
            } else {
                $types = 'addon';
            }
            $post['type'] = $types;
            return $this->upload($post);
        }
        return self::$output->withCode(27013);
    }

    /**
     * @inheritDoc
     */
    public function upload($post): OutputInterface
    {
        if (is_string($post)) {
            return $this->h5($post);
        }
        if (empty($post['files']['tmp_name'])) {
            return self::$output->withCode(23001);
        }
        $post['type'] = empty($post['type']) ? 'image' : $post['type'];

        $dirname = $this->getSaveDir(aval($post, 'dir'));
        $imgdir = CSPUBLIC . $dirname;
        File::mkdir($imgdir);

        $not_allow = aval(self::$setting, 'security/uploadForbidFile', 'php|pl|cgi|asp|aspx|jsp|php3|shtm|shtml|js');
        $file_name = trim(preg_replace("#[ \r\n\t\*\%\\\/\?><\|\":]{1,}#", '', $post['files']['name']));
        if (!empty($file_name) && (preg_match("#\.(" . $not_allow . ")$#i", $file_name) || strpos($file_name, '.') === false)) {
            @unlink($post['files']['tmp_name']);
            return self::$output->withCode(23004);
        }

        //防止伪装成图片的木马上传
        $checkWords = aval(self::$setting, 'security/uploadCheckWords');
        if (!empty($checkWords) && preg_match('/(' . $checkWords . ')/i', file_get_contents($post['files']['tmp_name']))) {
            @unlink($post['files']['tmp_name']);
            return self::$output->withCode(23005);
        }
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        //源文件类型检查
        $code = '';
        switch ($post['type']) {
            case 'image':
                if (strpos(self::$config['imgtype'], $ext) === false) {
                    $code = 23006;
                    break;
                }
                $info = getimagesize($post['files']['tmp_name']);
                //检测文件类型
                if (!is_array($info) || !in_array($info[2], [1, 2, 3, 6])) {
                    $code = 23001;
                }
                break;
            case 'flash':
                if ($ext != 'swf') {
                    $code = 23007;
                }
                break;
            case 'media':
                if (strpos(self::$config['mediatype'], $ext) === false) {
                    $code = 23008;
                }
                break;
            case 'addon':
                $subject = self::$config['imgtype'] . '|' . self::$config['mediatype'] . '|' . self::$config['softtype'];
                $allAllowType = str_replace('||', '|', $subject);
                if (strpos($allAllowType, $ext) === false) {
                    $code = 23009;
                }
                break;
            default:
                $code = 23010;
        }
        if (@filesize($post['files']['tmp_name']) > self::$config['maxUploadSize'] * 1024) {
            $code = 23012;
        }
        if ($code) {
            @unlink($post['files']['tmp_name']);
            return self::$output->withCode($code);
        }

        $filename = $imgdir . str_replace('.', '', uniqid(substr(md5(Ipdata::getip()), 20), true)) . '.' . $ext;
        $uploadPost = [];
        $uploadPost['attachment'] = new UploadedFile($post['files']['tmp_name'], $post['files']['name'], $post['files']['type']);
        $upload = self::$request->getRequest()->withUploadedFiles($uploadPost)->getUploadedFiles();
        $upload['attachment']->moveTo($filename);
        //加水印或缩小图片
        if ($post['type'] == 'image') {
            Image::imageResize($filename, aval($post, 'width'), aval($post, 'height'));
            (!empty($post['water']) || !empty(self::$config['waterMark'])) && Image::waterImg($filename);
        }

        $fileurl = str_replace(CSPUBLIC, '/', $filename);
        //保存信息到数据库
        $this->save($fileurl, 1);
        return self::$output->withCode(200)->withData(['fileurl' => $fileurl]);
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
        return self::t('uploads')->insert($data, true);
    }

    /**
     * @inheritDoc
     */
    public function webupload(array $post): OutputInterface
    {
        isset($_SESSION) ? '' : @session_start();

        if (empty($post['fileid'])) {
            return self::$output->withCode(23001);
        }
        if (!empty($_SESSION['bigfile_info']) && count($_SESSION['bigfile_info']) >= 10) {
            return self::$output->withCode(23002);
        }
        $post['width'] = aval($post, 'width');
        $post['height'] = aval($post, 'height');
        $post['type'] = 'image';
        $result = $this->upload($post);
        if ($result->getCode() != 200) {
            return $result;
        }

        $file = $result->getData();
        $img120 = $this->copyImage($file['fileurl'], 120, 120);
        $imagevariable = file_get_contents(CSPUBLIC . str_replace(self::$config['basehost'], '', $img120));

        //保存信息到 session
        if (!isset($_SESSION['file_info'])) {
            $_SESSION['file_info'] = [];
        }
        if (!isset($_SESSION['bigfile_info'])) {
            $_SESSION['bigfile_info'] = [];
        }
        $_SESSION['fileid'] = $post['fileid'];
        $_SESSION['bigfile_info'][$post['fileid']] = $file['fileurl'];
        $_SESSION['file_info'][$post['fileid']] = $imagevariable;
        $data = ['fileid' => $post['fileid'], 'imgurl' => $img120];
        return self::$output->withCode(200)->withData($data);
    }

    /**
     * @inheritDoc
     */
    public function getWebupload(): OutputInterface
    {
        $imgurls = [];
        isset($_SESSION) ? '' : @session_start();
        if (!empty($_SESSION['bigfile_info'])) {
            if (count($_SESSION['bigfile_info']) > 10) {
                $_SESSION['bigfile_info'] = [];
                foreach ($_SESSION['bigfile_info'] as $_v) {
                    $this->uploadDel($_v['img']);
                }
                return self::$output->withCode(21045);
            }
            if (is_array($_SESSION['bigfile_info'])) {
                foreach ($_SESSION['bigfile_info'] as $_k => $_v) {
                    if ($imginfos = getimagesize(CSPUBLIC . ltrim($_v, '/'))) {
                        $key = md5($_v);
                        $imgurls[$key]['img'] = $_v;
                        $imgurls[$key]['text'] = self::input('picinfook' . $_k);
                        $imgurls[$key]['width'] = $imginfos[0];
                        $imgurls[$key]['height'] = $imginfos[1];
                    }
                }
            }
        }
        $_SESSION['bigfile_info'] = [];
        return self::$output->withCode(200)->withData($imgurls);
    }

    /**
     * @inheritDoc
     */
    public function uploadDel(string $url): OutputInterface
    {
        if (empty($url)) {
            return self::$output->withCode(21002);
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
            self::t('uploads')->withWhere(['id' => $ids])->delete();
        }
        return self::$output->withCode(200);
    }

    /**
     * 获取某附件及相关附件
     * @param string $url
     * @return array
     * @throws \SlimCMS\Error\TextException
     */
    protected function listByUrl(string $url): array
    {
        $url = str_replace(self::$config['basehost'], '', $url);
        $ext = self::$config['imgtype'] . '|' . self::$config['softtype'] . '|' . self::$config['mediatype'];
        if (empty($url) || preg_match('#http:\/\/#i', $url) || !preg_match('#\.(' . $ext . ')#', $url)) {
            return [];
        }
        if (strpos($url, '_')) {
            $url = preg_replace('#(.*)(_)?(\d+)?(x)?(\d+)?(\.(' . self::$config['imgtype'] . ')){1}#isU', '\\1', $url);
        } else {
            $url = pathinfo($url, PATHINFO_DIRNAME) . '/' . pathinfo($url, PATHINFO_FILENAME);
        }
        $url = str_replace("'", '', $url);
        $where = [];
        $where[] = self::t('uploads')->field('url', $url . '%', 'like');
        return self::t('uploads')->withWhere($where)->fetchList();
    }

    /**
     * @inheritDoc
     */
    public function metaInfo(string $url, string $info = 'url,size'): OutputInterface
    {
        if (empty($url)) {
            return self::$output->withCode(21002);
        }
        $data = [];
        $arr = explode(',', $info);
        if (in_array('url', $arr)) {
            $data['url'] = trim(self::$config['attachmentHost'], '/') . $url;
        }
        if (in_array('size', $arr)) {
            $data['size'] = filesize(CSPUBLIC . $url);
        }
        if (in_array('width', $arr) || in_array('height', $arr)) {
            $info = getimagesize(CSPUBLIC . $url);
            $data['width'] = $info[0];
            $data['height'] = $info[1];
        }
        return self::$output->withCode(200)->withData($data);
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
        $attachmentHost = !empty(self::$config['attachmentHost']) ? self::$config['attachmentHost'] : self::$config['basehost'];
        $attachmentHost = rtrim($attachmentHost, '/') . '/';
        if (preg_match('/' . self::$config['domain'] . '/i', $pic)) {
            $pic = str_replace(rtrim(self::$config['basehost'], '/'), '', $pic);
        }
        if (preg_match("/^(https?:\/\/)/i", $pic)) {
            return $pic;
        }
        $ext = pathinfo($pic, PATHINFO_EXTENSION);
        if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'png'])) {
            return rtrim(self::$config['basehost'], '/') . $pic;
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
            if (@copy($oldurl, $newurl) && is_file($newurl) && Image::resize($newurl, $width, $height)) {
                $this->save('/' . $newpic);
            }
            return $attachmentHost . $newpic;
        }
        return $attachmentHost . $pic;
    }

    /**
     * @inheritDoc
     */
    public function superFileUpload(array $file, int $index, string $filename, string $diyDir = ''): OutputInterface
    {
        if (empty($file['tmp_name']) || empty($index) || empty($filename)) {
            return self::$output->withCode(21002);
        }

        $not_allow = aval(self::$setting, 'security/uploadForbidFile', 'php|pl|cgi|asp|aspx|jsp|php3|shtm|shtml|js');
        if (preg_match("#\.(" . $not_allow . ")$#i", $filename)) {
            @unlink($file['tmp_name']);
            return self::$output->withCode(23004);
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $subject = self::$config['imgtype'] . '|' . self::$config['mediatype'] . '|' . self::$config['softtype'];
        $allAllowType = str_replace('||', '|', $subject);
        if (strpos($allAllowType, $ext) === false) {
            return self::$output->withCode(23009);
        }

        $cachekey = __FUNCTION__;
        $cookie = self::$container->get(CookieInterface::class);
        if ($index == 1) {
            $cookie->set($cachekey, md5((string)microtime(true) . mt_rand(1000, 9999)), 3600);
        }

        $md5filename = $cookie->get($cachekey) ?: md5($filename);
        $dir = $this->getSaveDir($diyDir);
        File::mkdir(CSPUBLIC . $dir);
        $path = CSPUBLIC . $dir . $md5filename . '_' . $index;
        $json = [];
        if (!move_uploaded_file($file['tmp_name'], $path)) {
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
        return self::$output->withCode(200)->withData($json);
    }
}
