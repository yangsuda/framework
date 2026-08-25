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
            $str = json_encode($str);
        }
        $config = getConfig();
        $keys = &$config['settings']['keys'];
        $key = hash('sha256', $keys['key']);
        // 每次加密随机生成 IV，杜绝 IV 复用引发的明文相等泄露与 Padding Oracle 风险
        $iv = random_bytes(16);
        $ct = openssl_encrypt((string)$str, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ct === false) {
            return '';
        }
        // IV 随密文一起存储：base64(IV + ciphertext)
        return base64_encode($iv . $ct);
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
        $config = getConfig();
        $keys = &$config['settings']['keys'];
        $key = hash('sha256', $keys['key']);

        // 新格式：base64(IV + ciphertext)，IV 随机且随密文一起存储
        $raw = base64_decode($str, true);
        if ($raw === false || strlen($raw) < 16) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $ct = substr($raw, 16);
        $data = openssl_decrypt($ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $data === false ? '' : self::unpackPlaintext($data);
    }

    /**
     * 解密明文整理：JSON 字符串还原为数组，其余原样返回
     */
    private static function unpackPlaintext(string $data)
    {
        if (!empty($data) && Str::isJson($data)) {
            $arr = json_decode($data, true);
            if (is_array($arr)) {
                return $arr;
            }
        }
        return $data;
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
     * @param string $pwd 密码
     * @param string $hash 哈希值
     * @return string
     */
    public static function pwdVerify(string $pwd, string $hash): bool
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
