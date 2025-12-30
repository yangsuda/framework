<?php

/**
 * 加解密类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Helper;

class Crypt
{
    /**
     * 加密
     * @param $str
     * @return string
     */
    public static function encrypt($str): string
    {
        if (empty($str)) {
            return '';
        }
        if (is_array($str)) {
            $str = serialize($str);
        }
        $config = getConfig();
        $keys = &$config['settings']['keys'];
        $key = hash('sha256', $keys['key']);
        $iv = substr(hash('sha256', $keys['iv']), 0, 16);
        $str = (string)openssl_encrypt((string)$str, 'AES-256-CBC', $key, 0, $iv);
        $str = str_replace('+', '.', $str);
        return $str;
    }

    /**
     * 解密
     * @param $str
     * @return mixed|string
     */
    public static function decrypt(string $str)
    {
        if (empty($str)) {
            return '';
        }
        $str = str_replace('.', '+', $str);
        $str = urldecode(str_replace('%25', '%', urlencode($str)));
        $config = getConfig();
        $keys = &$config['settings']['keys'];
        $key = hash('sha256', $keys['key']);
        $iv = substr(hash('sha256', $keys['iv']), 0, 16);
        $data = openssl_decrypt($str, 'AES-256-CBC', $key, 0, $iv);
        $result = '';
        if (!empty($data) && preg_match("/^[a]:[0-9]+:{(.*)}$/", $data)) {
            $result = unserialize($data);
        }
        if (!is_array($result)) {
            $result = $data;
        }
        return $result;
    }

    /**
     * 生成系统密码
     * @param $pwd
     * @return bool|string
     */
    public static function pwd($pwd, $algo = PASSWORD_DEFAULT, array $options = []): string
    {
        $config = getConfig();
        $settings = &$config['settings'];
        return password_hash($pwd . $settings['security']['authkey'], $algo, $options);
    }

    /**
     * 密码校验
     * @param $pwd 密码
     * @param $hash 哈希值
     * @return string
     */
    public static function pwdVerify($pwd, string $hash): bool
    {
        $config = getConfig();
        $settings = &$config['settings'];
        return password_verify($pwd . $settings['security']['authkey'], $hash);
    }

    /**
     * openssl解密(前端加密信息如果是数字要转成字符串后再加密，否则解不出来)
     * @param string $data 加密信息
     * @param string $privateKey 私钥URL
     * @return string
     */
    public static function opensslDecrypt(string $data, string $privateKey): string
    {
        if (empty($data)) {
            return $data;
        }
        $private_key = openssl_get_privatekey(file_get_contents($privateKey));
        $encrypt_data = base64_decode($data);
        openssl_private_decrypt($encrypt_data, $result, $private_key);
        openssl_free_key($private_key);
        return $result ?: '';
    }

    /**
     * openssl加密
     * @param string $data 加密信息
     * @param string $publicKey 公钥URL
     * @return string
     */
    public static function opensslEncrypt(string $data, string $publicKey): string
    {
        if (empty($data)) {
            return $data;
        }
        $encrypted = '';
        openssl_public_encrypt($data, $encrypted, file_get_contents($publicKey));
        $data = base64_encode($encrypted);
        return $data ?: '';
    }
}
