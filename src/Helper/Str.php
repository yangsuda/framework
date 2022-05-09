<?php
/**
 * 字符串处理类
 */

namespace SlimCMS\Helper;

class Str
{
    /**
     * 解析伪静态或加密q参数
     * @param $q
     */
    public static function QAnalysis($q)
    {
        $data = [];
        if (!empty($q)) {
            if (strpos($q, '-')) {
                $str = urldecode(urldecode($q));
                $gets = explode('_', $str);
                foreach ($gets as $v) {
                    $keyval = explode('-', $v);
                    if (!empty($keyval[1]) || $keyval[1] == '0') {
                        $key = str_replace(['&#045;', '&#095;', '&#47;'], ['-', '_', '/'], $keyval[0]);
                        $val = str_replace(['&#045;', '&#095;', '&#47;'], ['-', '_', '/'], $keyval[1]);
                        $data[$key] = $val;
                    }
                }
            } else {
                $q = Crypt::decrypt((string)$q);
                if ($q) {
                    foreach ($q as $k => $v) {
                        $data[$k] = $v;
                    }
                }
            }
        }
        return $data;
    }

    /**
     * 将缓存KEY中含有的时间戳后3位改成000，否则缓存会一直生成，失去缓存意义
     */
    public static function md5key(array $condition = []): string
    {
        $key = str_replace("'", '', var_export($condition, true));
        preg_match_all('/(1[0-9]{9})/', $key, $matches);
        if (empty($matches[1][0])) {
            return md5($key);
        }
        $arr = explode($matches[1][0], $key);
        if (!empty($arr[1]) && is_numeric($arr[1][0])) {
            return $key;
        }
        $search = $replace = array();
        foreach ($matches[1] as $v) {
            $search[] = $v;
            $replace[] = substr($v, 0, -3) . '000';
        }
        return md5(str_replace($search, $replace, $key));
    }

    public static function random(int $length, int $numeric = 0)
    {
        $seed = base_convert(md5(microtime() . $_SERVER['DOCUMENT_ROOT']), 16, $numeric ? 10 : 35);
        $seed = $numeric ? (str_replace('0', '', $seed) . '012340567890') : ($seed . 'zZ' . strtoupper($seed));
        if ($numeric) {
            $hash = '';
        } else {
            $hash = chr(rand(1, 26) + rand(0, 1) * 32 + 64);
            $length--;
        }
        $max = strlen($seed) - 1;
        for ($i = 0; $i < $length; $i++) {
            $hash .= $seed[mt_rand(0, $max)];
        }
        return $hash;
    }

    public static function delHtml(string $document, int $type = 1): string
    {
        if ($type == 1) {
            $document = preg_replace("#<\/p>#i", "\n", $document);
            $document = preg_replace("#<br>#i", "\n", $document);
            $document = preg_replace("#<br \/>#i", "\n", $document);
            $search = [
                "'<script[^>]*?>.*?</script>'si", // 去掉 javascript
                "'<[\/\!]*?[^<>]*?>'si", // 去掉 HTML 标记
                "'([\r\n])[\s]+'", // 去掉空白字符
                "'#p#|#e#'i", // 去掉分页标记
                "'&(quot|#34);'i", // 替换 HTML 实体
                "'&(amp|#38);'i",
                "'&(lt|#60);'i",
                "'&(gt|#62);'i",
                "'&(nbsp|#160);'i"];

            $replace = [
                "",
                "",
                "",
                "\\1",
                "\"",
                "&",
                "<",
                ">",
                " "];

            $document = preg_replace($search, $replace, $document);
            return trim(strip_tags($document));
        }
        //过滤非汉字字母数字字符
        if ($type == 2) {
            return preg_replace("/&[A-Za-z].*;|[^" . chr(0x80) . "-" . chr(0xff) . "\w,]/isU", "", strip_tags($document));
        }
        //过滤掉CSS、JS、HTML代码
        if ($type == 3) {
            $str = preg_replace("/<sty(.*)\\/style>|<scr(.*)\\/script>|<!--(.*)-->/isU", "", $document);
            $alltext = "";
            $start = 1;
            for ($i = 0; $i < strlen($str); $i++) {
                if ($start == 0 && $str[$i] == ">") {
                    $start = 1;
                } else if ($start == 1) {
                    if ($str[$i] == "<") {
                        $start = 0;
                        $alltext .= " ";
                    } else if (ord($str[$i]) > 31) {
                        $alltext .= $str[$i];
                    }
                }
            }
            $alltext = preg_replace("/&([^;&]*)(;|&)/", "", $alltext);
            $alltext = preg_replace("/[ ]+/s", " ", $alltext);
            return $alltext;
        }
    }

    /**
     * 截取字符串
     * @param string $str
     * @param int $start
     * @param int $length
     * @param string $etc
     * @return string
     */
    public static function substr(string $str, int $start, int $length, string $etc = ''): string
    {
        $i = 0;
        //完整排除之前的UTF8字符
        while ($i < $start) {
            $ord = ord($str[$i]);
            if ($ord < 192) {
                $i++;
            } elseif ($ord < 224) {
                $i += 2;
            } else {
                $i += 3;
            }
        }
        //开始截取
        $result = '';
        while ($i < $start + $length && $i < strlen($str)) {
            $ord = ord($str[$i]);
            if ($ord < 192) {
                $result .= $str[$i];
                $i++;
            } elseif ($ord < 224) {
                $result .= $str[$i] . $str[$i + 1];
                $i += 2;
            } else {
                $result .= $str[$i] . $str[$i + 1] . $str[$i + 2];
                $i += 3;
            }
        }
        if ($i < strlen($str)) {
            $result .= $etc;
        }
        return $result;
    }

    public static function round(float $val, int $lang = 0)
    {
        return !empty($val) ? round($val, $lang) : '';
    }

    /**
     * 过滤内容数据
     * @param string $html
     * @return string
     */
    public static function filterHtml(string $html): string
    {
        $html = preg_replace(array("'<div.*?>'si", "'</div>'si", "'<p><img(.*?)>'si"), array("", "", "<p style='text-align:center'><img\\1></p>\n<p>"), $html);
        $html = self::filterDangerImg($html);
        return preg_replace("/<sty(.*)\\/style>|<scr(.*)\\/script>|<!--(.*)-->/isU", "", $html);
    }

    /**
     * 过滤掉字符串中带有非图片url的图片链接
     * @param string $html 要过滤的字符串
     * @return string 过滤后的字符串
     */
    private static function filterDangerImg(string $html): string
    {
        preg_match_all("/<img src(.*?)=(.*?)>/i", $html, $match);
        $danger_urls = [];
        $replace = [];
        if (count($match) == 3) {
            foreach ($match[2] as $v) {
                if (preg_match("/(.php|\?|&)/i", $v, $m)) {
                    $danger_urls[] = $v;
                    $replace[] = '';
                    continue;
                }
                if (!preg_match("/(\.gif|\.jpg|\.png)/i", $v, $m)) {
                    $danger_urls[] = $v;
                    $replace[] = '';
                    continue;
                }
            }
        }
        return str_replace($danger_urls, $replace, $html);
    }

    /**
     * 数据序列化
     * @param type $str 需要处理的数据
     * @return string
     */
    public static function serializeData($str)
    {
        if ($str) {
            $arr = explode("\n", $str);
            $row = array();
            $i = 0;
            if (!preg_match('/=/', $str)) {
                return $str;
            }
            foreach ($arr as $v) {
                $i++;
                $value = trim(stristr($v, '='), '=');
                $keys = trim(stristr($v, '=', true));
                $keys = trim($keys);
                if (!empty($row[$keys])) {
                    $row[$i . '#' . $keys] = trim($value);
                } else {
                    $row[$keys] = trim($value);
                }
            }
            return serialize($row);
        }
        return '';
    }

    /**
     * 数据反序列化
     * @param type $str 需要处理的数据
     * @return string
     */
    public static function unserializeData($data)
    {
        $str = '';
        if (!empty($data)) {
            $arr = unserialize($data);
            if (empty($arr)) {
                return $data;
            }
            foreach ($arr as $k => $v) {
                $str .= $k . '=' . $v . "\n";
            }
        }
        return trim($str, "\n");
    }

    /**
     * 判断是否是UTF8编码
     * @param string $word
     * @return bool
     */
    public static function isUTF8(string $word): bool
    {
        $word = preg_replace('/[\w]/i', '', $word);
        if (preg_match("/^([" . chr(228) . "-" . chr(233) . "]{1}[" . chr(128) . "-" . chr(191) . "]{1}[" . chr(128) . "-" . chr(191) . "]{1}){1}/", $word) == true || preg_match("/([" . chr(228) . "-" . chr(233) . "]{1}[" . chr(128) . "-" . chr(191) . "]{1}[" . chr(128) . "-" . chr(191) . "]{1}){1}$/", $word) == true || preg_match("/([" . chr(228) . "-" . chr(233) . "]{1}[" . chr(128) . "-" . chr(191) . "]{1}[" . chr(128) . "-" . chr(191) . "]{1}){2,}/", $word) == true) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 转成UFT8编码
     * @param $text
     * @return array|false|string|string[]|null
     */
    public static function toUTF8($text)
    {
        if (!empty($text) && is_array($text)) {
            foreach ($text as $k => $v) {
                $text[$k] = self::toUTF8($v);
            }
            return $text;
        } else {
            $text = preg_replace('/[\w]/i', '', $text);
            if (self::isUTF8($text)) {
                return $text;
            }
            $coding = mb_detect_encoding($text, array('GB2312', 'GBK', 'ASCII', 'UTF-8', 'BIG5'));
            if (empty($coding)) {
                return mb_convert_encoding($text, 'utf-8', 'gbk');
            }
            return iconv($coding, 'utf-8', $text);
        }
    }

    /**
     * 用户提交数据过滤
     * @param $string
     * @param null $flags
     * @return array|mixed|null|string|string[]
     */
    public static function htmlspecialchars($string, $flags = null)
    {
        if (is_array($string)) {
            foreach ($string as $key => $val) {
                $string[$key] = self::htmlspecialchars($val, $flags);
            }
        } else {
            if (empty($flags)) {
                $string = str_replace(
                    ['&', '"', '<', '>', '\'', '||', '*', '$', '(', ')'],
                    ['&amp;', '&quot;', '&lt;', '&gt;', '&#039;', '&#124;&#124;', '&#042;', '&#036;', '&#040;', '&#041;'], $string);
                if (strpos($string, '&amp;#') !== false) {
                    $string = preg_replace('/&amp;((#(\d{3,5}|x[a-fA-F0-9]{4}));)/', '&\\1', $string);
                }
            } elseif ($flags == 'en') {
                $string = str_replace(array('&', '"', '<', '>', '\'', '(null)', '||', '*', '$', '(', ')'), array('&amp;', '&quot;', '&lt;', '&gt;', '&#039;', '&#040;null&#041;', '&#124;&#124;', '&#042;', '&#036;', '&#040;', '&#041;'), $string);
            } elseif ($flags == 'de') {
                $string = str_replace(
                    ['&#039;', '&#034;', '&#042;', '&quot;', '&ldquo;', '&rdquo;', '&amp;', '&#040;', '&#041;', '&lt;', '&gt;'],
                    ["'", '"', '*', '"', '“', '”', '&', '(', ')', '<', '>'], $string);
            }
        }
        return $string;
    }

    /**
     * 对富文本进行转译
     * @param $string
     * @return array|string
     */
    public static function addslashes($string)
    {
        if (is_array($string)) {
            $keys = array_keys($string);
            foreach ($keys as $key) {
                $string[addslashes($key)] = self::addslashes($string[$key]);
            }
        } else {
            $string = addslashes($string);
        }
        return $string;
    }

    /**
     * 对转译过的富文本还原
     * @param $string
     * @return array|string
     */
    public static function stripslashes($string)
    {
        if (empty($string)) {
            return $string;
        }
        if (is_array($string)) {
            foreach ($string as $key => $val) {
                $string[$key] = self::stripslashes($val);
            }
        } else {
            $string = stripslashes($string);
        }
        return $string;
    }

    /**
     * emoji表情转换
     * @param string $str 要转换字符串
     * @param int $type 转换方式，0过滤掉emoji，1转成Unicode编码,2将unicode编码转成emoji
     * @return string
     */
    public static function filterEmoji(string $str, int $type = 0): string
    {
        if ($type == 2) {
            return preg_replace_callback(
                '/&#([0-9]+);/is',
                function (array $match) {
                    return iconv("UCS-4", 'UTF-8', hex2bin('000' . base_convert($match[1], 10, 16)));
                },
                $str);
        }
        return preg_replace_callback(
            '/./u',
            function (array $match) use ($type) {
                $replace = $type == 1 ? "&#" . base_convert(bin2hex(iconv('UTF-8', "UCS-4", $match[0])), 16, 10) . ';' : '';
                return strlen($match[0]) >= 4 ? $replace : $match[0];
            },
            $str);

    }
}
