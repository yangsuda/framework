<?php
/**
 * 微信公众号类
 * @author zhucy
 * @date 2020.01.07
 */

declare(strict_types=1);

namespace SlimCMS\Core;

use SlimCMS\Abstracts\ModelAbstract;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\File;
use SlimCMS\Helper\Http;
use SlimCMS\Interfaces\OutputInterface;

class Wxgzh extends ModelAbstract
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
            $cacheFile = $dir . 'gzh_' . $data['appid'] . '.txt';
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
     * 获取CODE
     * @param OutputInterface $output
     * @return OutputInterface
     */
    public static function getCode(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (empty($data['appid']) || empty($data['appsecret'])) {
            return self::$output->withCode(21003);
        }
        $scope = aval($data, 'scope') == 'base' ? 'base' : 'userinfo';
        $referer = "https://open.weixin.qq.com/connect/oauth2/authorize?appid=" . $data['appid'] .
            "&redirect_uri=" . urlencode(aval($data, 'redirect')) . "&response_type=code&scope=snsapi_" . $scope .
            "&state=#wechat_redirect";
        return self::$output->withReferer($referer);
    }

    /**
     * 获取公众号用户信息
     * @param OutputInterface $output
     * @return OutputInterface
     */
    public static function getUserInfo(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (empty($data['appid']) || empty($data['appsecret']) || empty($data['code'])) {
            return self::$output->withCode(21003);
        }
        $wxinfo = Http::curlGet("https://api.weixin.qq.com/sns/oauth2/access_token?appid=" . $data['appid'] .
            "&secret=" . $data['appsecret'] . "&code=" . $data['code'] . "&grant_type=authorization_code");
        $wxinfo = json_decode($wxinfo, true);
        if (aval($wxinfo, 'scope') == 'snsapi_userinfo' && $wxinfo['access_token'] && $wxinfo['openid']) {
            $wxinfo = json_decode(Http::curlGet("https://api.weixin.qq.com/sns/userinfo?access_token="
                . $wxinfo['access_token'] . "&openid=" . $wxinfo['openid'] . "&lang=zh_CN"), true);
        }
        if (!empty($wxinfo['errcode'])) {
            return self::$output->withCode(21000, ['msg' => $wxinfo['errmsg']]);
        }
        return self::$output->withCode(200)->withData(['wxuser' => $wxinfo]);
    }

    /**
     * 发送模板消息
     * @param OutputInterface $output
     * @return OutputInterface
     */
    public static function sendTemplateMessage(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (!self::$accessToken) {
            $res = self::getAccessToken($output);
            if ($res->getCode() != 200) {
                return $res;
            }
        }
        if (empty($data['touser']) || empty($data['template_id'])|| empty($data['data'])) {
            return self::$output->withCode(21003);
        }
        $val = [];
        $val['touser'] = $data['touser'];
        $val['template_id'] = $data['template_id'];
        $val['data'] = $data['data'];
        isset($data['url']) && $val['url'] = $data['url'];
        !empty($data['miniprogram']) && $val['miniprogram'] = $data['miniprogram'];

        $url = 'https://api.weixin.qq.com/cgi-bin/message/template/send?access_token=' . self::$accessToken;
        $result = Http::curlPost($url, json_encode($val));
        $obj = json_decode($result, true);
        if (!empty($obj['errcode'])) {
            return self::$output->withCode(21000, ['msg' => $obj['errmsg']]);
        }
        return self::$output->withCode(200);
    }

    /**
     * 生成微信分享签名
     * @param $url
     * @return array
     */
    public static function wxJsapiConfig(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        if (empty($data['appid']) || empty($data['appsecret']) || empty($data['url'])) {
            return self::$output->withCode(21003);
        }
        $ticket = self::jsapiTicket($output);
        if (empty($ticket)) {
            return self::$output->withCode(23003);
        }
        $val = [];
        $val['appid'] = $data['appid'];
        $val['ticket'] = $ticket;
        $val['noncestr'] = md5((string)TIMESTAMP);
        $val['timestamp'] = TIMESTAMP;
        $val['url'] = $data['url'];
        $val['signature'] = sha1('jsapi_ticket=' . $ticket . '&noncestr=' . $val['noncestr'] .
            '&timestamp=' . $val['timestamp'] . '&url=' . $val['url']);
        return self::$output->withCode(200)->withData($val);
    }

    /**
     * 获取api_ticket
     * @param OutputInterface $output
     * @return bool|false|string
     * @throws TextException
     */
    protected static function jsapiTicket(OutputInterface $output)
    {
        $data = $output->getData();
        if (!self::$accessToken) {
            $res = self::getAccessToken($output);
            if ($res->getCode() != 200) {
                throw new TextException($res->getCode(), ['msg'=>$res->getMsg()], 'wxgzh');
            }
        }
        $url = 'https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token=' . self::$accessToken . '&type=jsapi';
        if (self::$redis->isAvailable()) {
            $cachekey = self::cacheKey(__FUNCTION__, $data['appid']);
            $ticket = self::$redis->get($cachekey);
            if (empty($ticket)) {
                if (!self::$redis->setnx($cachekey . 'setnx', '1', 600)) {
                    return false;
                }
                $str = Http::curlGet($url);
                $re = json_decode($str, true);
                File::log('wx/jsapiTicket')->info('获取api_ticket', $re);
                if (!empty($re['ticket'])) {
                    self::$redis->set($cachekey, $re['ticket'], 7000);
                    return $re['ticket'];
                }
                return false;
            }
            return $ticket;
        } else {
            $dir = CSDATA . 'wx/jsapiTicket/';
            File::mkdir($dir);
            $cacheFile = $dir . $data['appid'] . '.txt';
            $filemtime = is_file($cacheFile) ? filemtime($cacheFile) : 0;
            if (TIMESTAMP - $filemtime < 7000) {
                return file_get_contents($cacheFile);
            } else {
                $str = Http::curlGet($url);
                $re = json_decode($str, true);
                File::log('wx/jsapiTicket')->info('获取api_ticket', $re);
                if (!empty($re['ticket'])) {
                    file_put_contents($cacheFile, $re['ticket']);
                    return $re['ticket'];
                }
                return false;
            }
        }
    }
}
