<?php
/**
 * 微信小程序类
 * @author zhucy
 * @date 2020.03.24
 */

declare(strict_types=1);

namespace SlimCMS\Core;


use SlimCMS\Abstracts\BaseAbstract;
use SlimCMS\Helper\File;
use SlimCMS\Helper\Http;
use SlimCMS\Interfaces\OutputInterface;

class Wxxcx extends BaseAbstract
{
    protected $accessToken = '';

    private Redis $redis;
    private OutputInterface $output;

    public function __construct(App $app, Redis $redis)
    {
        parent::__construct($app);
        $this->redis = $redis;
        $this->output = $this->container->get(OutputInterface::class)($app);
    }

    /**
     * 获取access_token
     * @param OutputInterface $output
     * @return OutputInterface
     */
    public function getAccessToken(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (empty($data['appid']) || empty($data['appsecret'])) {
            return $this->output->withCode(21003);
        }
        $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=' . $data['appid'] . '&secret=' . $data['appsecret'];
        if ($this->redis->isAvailable()) {
            $cachekey = $this->cacheKey(__FUNCTION__, $data['appid']);
            $this->accessToken = $this->redis->get($cachekey);
            if (!$this->accessToken) {
                $str = Http::curlGet($url);
                $obj = json_decode($str, true);
                if (!empty($obj['access_token'])) {
                    $this->accessToken = $obj['access_token'];
                    $this->redis->set($cachekey, $this->accessToken, 7000);
                } else {
                    return $this->output->withCode(21000, $obj['errmsg']);
                }
            }
        } else {
            $dir = CSDATA . 'wx/accessToken/';
            File::mkdir($dir);
            $cacheFile = $dir . 'xcx_' . $data['appid'] . '.txt';
            $filemtime = is_file($cacheFile) ? filemtime($cacheFile) : 0;
            if (TIMESTAMP - $filemtime < 7000) {
                $this->accessToken = file_get_contents($cacheFile);
            } else {
                $str = Http::curlGet($url);
                $obj = json_decode($str, true);
                if (!empty($obj['access_token'])) {
                    file_put_contents($cacheFile, $obj['access_token']);
                    $this->accessToken = $obj['access_token'];
                } else {
                    return $this->output->withCode(21000, $obj['errmsg']);
                }
            }
        }
        return $this->output->withCode(200)->withData(['accessToken' => $this->accessToken]);
    }

    /**
     * 生成的小程序码，永久有效，暂时数量暂无限制,返回的是base64加密的二进制流
     * @param OutputInterface $output
     * @return OutputInterface
     */
    public function getwxacodeunlimit(OutputInterface $output): OutputInterface
    {
        if (!$this->accessToken) {
            $res = $this->getAccessToken($output);
            if ($res->getCode() != 200) {
                return $res;
            }
        }
        $data = $output->getData();
        if (empty($data['scene'])) {
            return $this->output->withCode(21003);
        }
        $val = [];
        $val['scene'] = $data['scene'];
        !empty($data['page']) && $val['page'] = $data['page'];
        !empty($data['width']) && $val['width'] = $data['width'];
        isset($data['autoColor']) && $val['autoColor'] = $data['autoColor'];
        !empty($data['lineColor']) && $val['lineColor'] = $data['lineColor'];
        !empty($data['isHyaline']) && $val['isHyaline'] = $data['isHyaline'];

        $url = 'https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token=' . $this->accessToken;
        $result = Http::curlPost($url, json_encode($val));
        return $this->output->withCode(200)->withData(['qrcode' => base64_encode($result)]);
    }

    /**
     * 获取用户openid和session_key
     * @param OutputInterface $output
     * @return OutputInterface
     */
    public function getOpenid(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (empty($data['appid']) || empty($data['appsecret']) || empty($data['code'])) {
            return $this->output->withCode(21003);
        }
        $str = Http::curlGet('https://api.weixin.qq.com/sns/jscode2session?appid=' . $data['appid'] . '&secret=' . $data['appsecret'] . '&js_code=' . $data['code'] . '&grant_type=authorization_code');
        $obj = json_decode($str, true);
        if (!empty($obj['openid'])) {
            return $this->output->withCode(200)->withData($obj);
        }
        return $this->output->withCode(21000, $obj['errmsg']);
    }

    /**
     * 检验数据的真实性，并且获取解密后的明文.
     * @param $output ->getData()[appid] string 小程序appid
     * @param $output ->getData()[appsecret] string 小程序appsecret
     * @param $output ->getData()[encrypteddata] string 加密的用户数据
     * @param $output ->getData()[iv] string 与用户数据一同返回的初始向量
     * @param $output ->getData()[code] string 登录时获取的code
     * @return array
     */
    public function decryptData(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (empty($data['code']) || empty($data['iv']) || empty($data['encrypteddata'])) {
            return $this->output->withCode(21003);
        }
        $res = $this->getOpenid($output);
        if ($res->getCode() != 200) {
            return $res;
        }
        $data['sessionkey'] = $res->getData()['session_key'];
        if (strlen($data['sessionkey']) != 24) {
            return $this->output->withCode(22000);
        }
        $aesKey = base64_decode($data['sessionkey']);

        if (strlen($data['iv']) != 24) {
            return $this->output->withCode(22001);
        }
        $aesIV = base64_decode($data['iv']);

        $aesCipher = base64_decode($data['encrypteddata']);

        $result = openssl_decrypt($aesCipher, "AES-128-CBC", $aesKey, 1, $aesIV);

        $dataObj = !empty($result) ? json_decode($result, true) : null;
        if ($dataObj == NULL) {
            return $this->output->withCode(22002);
        }
        if ($dataObj['watermark']['appid'] != $data['appid']) {
            return $this->output->withCode(22002);
        }
        return $this->output->withCode(200)->withData($dataObj);
    }


    /**
     * 发送小程序订阅消息
     * @param OutputInterface $output
     * @return OutputInterface
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function sendTemplateMessage(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (!$this->accessToken) {
            $res = $this->getAccessToken($output);
            if ($res->getCode() != 200) {
                return $res;
            }
        }
        if (empty($data['touser']) || empty($data['template_id']) || empty($data['data'])) {
            return $this->output->withCode(21003);
        }
        $vals = [];
        $vals['touser'] = $data['touser'];
        $vals['template_id'] = $data['template_id'];
        $vals['page'] = aval($data, 'page');
        $vals['data'] = [];
        foreach ($data['data'] as $k => $v) {
            $vals['data'][$k]['value'] = $v;
        }
        $result = Http::curlPost('https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token=' . $this->accessToken, json_encode($vals));
        $obj = json_decode($result, true);
        if (!empty($obj['errcode'])) {
            File::log('wx/sendTemplateMessage')->info('发送小程序订阅消息', $obj);
            return $this->output->withCode(21000, $obj['errmsg']);
        }
        return $this->output->withCode(200);
    }

    /**
     * 通过code获取手机号码
     * @param $output ->getData()[appid] string 小程序appid
     * @param $output ->getData()[appsecret] string 小程序appsecret
     * @param $output ->getData()[code] string 登录时获取的code
     * @return OutputInterface[phoneNumber用户绑定的手机号,purePhoneNumber没有区号的手机号,countryCode区号]
     */
    public function getuserphonenumber(OutputInterface $output): OutputInterface
    {
        if (!$this->accessToken) {
            $res = $this->getAccessToken($output);
            if ($res->getCode() != 200) {
                return $res;
            }
        }
        $data = $output->getData();
        if (empty($data['code'])) {
            return $this->output->withCode(21003);
        }
        $val = [];
        $val['code'] = $data['code'];
        $url = 'https://api.weixin.qq.com/wxa/business/getuserphonenumber?access_token=' . $this->accessToken;
        $result = Http::curlPost($url, json_encode($val));
        $obj = json_decode($result, true);
        if (!empty($obj['errcode'])) {
            File::log('wx/getuserphonenumber')->info('获取手机号码', $obj);
            return $this->output->withCode(21000, $obj['errmsg']);
        }
        return $this->output->withCode(200)->withData($obj['phone_info']);
    }
}
