<?php
/**
 * 微信小程序类
 * @author zhucy
 * @date 2020.03.24
 */

declare(strict_types=1);

namespace SlimCMS\Core;


use SlimCMS\Abstracts\ModelAbstract;
use SlimCMS\Helper\File;
use SlimCMS\Helper\Http;
use SlimCMS\Interfaces\OutputInterface;

class Wxxcx extends ModelAbstract
{
    protected static $accessToken = '';

    /**
     * 获取access_token
     * @param OutputInterface $output
     * @return OutputInterface
     */
    public static function getAccessToken(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (empty($data['appid']) || empty($data['appsecret'])) {
            return self::$output->withCode(21003);
        }
        $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=' . $data['appid'] . '&secret=' . $data['appsecret'];
        if (self::$redis->isAvailable()) {
            $cachekey = self::cacheKey(__FUNCTION__, $data['appid']);
            self::$accessToken = self::$redis->get($cachekey);
            if (!self::$accessToken) {
                $str = Http::curlGet($url);
                $obj = json_decode($str, true);
                if (!empty($obj['access_token'])) {
                    self::$accessToken = $obj['access_token'];
                    self::$redis->set($cachekey, self::$accessToken, 7000);
                } else {
                    return self::$output->withCode(21000, ['msg' => $obj['errmsg']]);
                }
            }
        } else {
            $dir = CSDATA . 'wx/accessToken/';
            File::mkdir($dir);
            $cacheFile = $dir . 'xcx_' . $data['appid'] . '.txt';
            $filemtime = is_file($cacheFile) ? filemtime($cacheFile) : 0;
            if (TIMESTAMP - $filemtime < 7000) {
                self::$accessToken = file_get_contents($cacheFile);
            } else {
                $str = Http::curlGet($url);
                $obj = json_decode($str, true);
                if (!empty($obj['access_token'])) {
                    file_put_contents($cacheFile, $obj['access_token']);
                    self::$accessToken = $obj['access_token'];
                } else {
                    return self::$output->withCode(21000, ['msg' => $obj['errmsg']]);
                }
            }
        }
        return self::$output->withCode(200)->withData(['accessToken' => self::$accessToken]);
    }

    /**
     * 生成的小程序码，永久有效，暂时数量暂无限制,返回的是base64加密的二进制流
     * @param OutputInterface $output
     * @return OutputInterface
     */
    public static function getwxacodeunlimit(OutputInterface $output): OutputInterface
    {
        if (!self::$accessToken) {
            $res = static::getAccessToken($output);
            if ($res->getCode() != 200) {
                return $res;
            }
        }
        $data = $output->getData();
        if (empty($data['scene'])) {
            return self::$output->withCode(21003);
        }
        $val = [];
        $val['scene'] = $data['scene'];
        !empty($data['page']) && $val['page'] = $data['page'];
        !empty($data['width']) && $val['width'] = $data['width'];
        isset($data['autoColor']) && $val['autoColor'] = $data['autoColor'];
        !empty($data['lineColor']) && $val['lineColor'] = $data['lineColor'];
        !empty($data['isHyaline']) && $val['isHyaline'] = $data['isHyaline'];

        $url = 'https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token=' . self::$accessToken;
        $result = Http::curlPost($url, json_encode($val));
        return self::$output->withCode(200)->withData(['qrcode' => base64_encode($result)]);
    }

    /**
     * 获取用户openid和session_key
     * @param OutputInterface $output
     * @return OutputInterface
     */
    public static function getOpenid(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (empty($data['appid']) || empty($data['appsecret']) || empty($data['code'])) {
            return self::$output->withCode(21003);
        }
        $str = Http::curlGet('https://api.weixin.qq.com/sns/jscode2session?appid=' . $data['appid'] . '&secret=' . $data['appsecret'] . '&js_code=' . $data['code'] . '&grant_type=authorization_code');
        $obj = json_decode($str, true);
        if (!empty($obj['openid'])) {
            return self::$output->withCode(200)->withData($obj);
        }
        return self::$output->withCode(21000, ['msg' => $obj['errmsg']]);
    }

    /**
     * 检验数据的真实性，并且获取解密后的明文.
     * @param $output->getData()[appid] string 小程序appid
     * @param $output->getData()[appsecret] string 小程序appsecret
     * @param $output->getData()[encrypteddata] string 加密的用户数据
     * @param $output->getData()[iv] string 与用户数据一同返回的初始向量
     * @param $output->getData()[code] string 登录时获取的code
     * @return array
     */
    public static function decryptData(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (empty($data['code']) || empty($data['iv']) || empty($data['encrypteddata'])) {
            return self::$output->withCode(21003);
        }
        $res = self::getOpenid($output);
        if ($res->getCode() != 200) {
            return $res;
        }
        $data['sessionkey'] = $res->getData()['session_key'];
        if (strlen($data['sessionkey']) != 24) {
            return self::$output->withCode(22000);
        }
        $aesKey = base64_decode($data['sessionkey']);

        if (strlen($data['iv']) != 24) {
            return self::$output->withCode(22001);
        }
        $aesIV = base64_decode($data['iv']);

        $aesCipher = base64_decode($data['encrypteddata']);

        $result = openssl_decrypt($aesCipher, "AES-128-CBC", $aesKey, 1, $aesIV);

        $dataObj = !empty($result) ? json_decode($result, true) : null;
        if ($dataObj == NULL) {
            return self::$output->withCode(22002);
        }
        if ($dataObj['watermark']['appid'] != $data['appid']) {
            return self::$output->withCode(22002);
        }
        return self::$output->withCode(200)->withData($dataObj);
    }


    /**
     * 发送小程序订阅消息
     * @param OutputInterface $output
     * @return OutputInterface
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public static function sendTemplateMessage(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (!self::$accessToken) {
            $res = static::getAccessToken($output);
            if ($res->getCode() != 200) {
                return $res;
            }
        }
        if (empty($data['touser']) || empty($data['template_id']) || empty($data['data'])) {
            return self::$output->withCode(21003);
        }
        $vals = [];
        $vals['touser'] = $data['touser'];
        $vals['template_id'] = $data['template_id'];
        $vals['page'] = aval($data, 'page');
        $vals['data'] = [];
        foreach ($data['data'] as $k => $v) {
            $vals['data'][$k]['value'] = $v;
        }
        $result = Http::curlPost('https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token=' . self::$accessToken, json_encode($vals));
        $obj = json_decode($result, true);
        if (!empty($obj['errcode'])) {
            File::log('wx/sendTemplateMessage')->info('发送小程序订阅消息', $obj);
            return self::$output->withCode(21000, ['msg' => $obj['errmsg']]);
        }
        return self::$output->withCode(200);
    }

    /**
     * 通过code获取手机号码
     * @param $output->getData()[appid] string 小程序appid
     * @param $output->getData()[appsecret] string 小程序appsecret
     * @param $output->getData()[code] string 登录时获取的code
     * @return OutputInterface[phoneNumber用户绑定的手机号,purePhoneNumber没有区号的手机号,countryCode区号]
     */
    public static function getuserphonenumber(OutputInterface $output): OutputInterface
    {
        if (!self::$accessToken) {
            $res = static::getAccessToken($output);
            if ($res->getCode() != 200) {
                return $res;
            }
        }
        $data = $output->getData();
        if (empty($data['code'])) {
            return self::$output->withCode(21003);
        }
        $val = [];
        $val['code'] = $data['code'];
        $url = 'https://api.weixin.qq.com/wxa/business/getuserphonenumber?access_token=' . self::$accessToken;
        $result = Http::curlPost($url, json_encode($val));
        $obj = json_decode($result, true);
        if (!empty($obj['errcode'])) {
            File::log('wx/getuserphonenumber')->info('获取手机号码', $obj);
            return self::$output->withCode(21000, ['msg' => $obj['errmsg']]);
        }
        return self::$output->withCode(200)->withData($obj['phone_info']);
    }
}
