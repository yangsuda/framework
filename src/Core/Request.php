<?php
/**
 * 外部请求处理类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Core;

use SlimCMS\Error\TextException;
use SlimCMS\Abstracts\MessageAbstract;
use SlimCMS\Helper\Str;
use SlimCMS\Interfaces\UploadInterface;

class Request extends MessageAbstract
{
    /**
     * 获取外部传参
     * @param $name
     * @param string $type
     * @return array|mixed|\都不存在时的默认值|null
     */
    public function input($name, $type = 'string')
    {
        if (is_array($name)) {
            return $this->inputData($name);
        }
        $val = $this->inputData([$name => $type]);
        return aval($val, $name);
    }

    /**
     * 过滤并返回外部传入数据
     * @param $k
     * @return array|mixed|string|null
     */
    protected function getInput(string $k)
    {
        $post = $this->request->getParsedBody();
        if (isset($post[$k])) {
            return $this->wordsFilter($post[$k], $k);
        }
        if (isset($_GET[$k])) {
            return $this->wordsFilter($_GET[$k], $k);
        }
        return NULL;
    }

    /**
     * 违禁词处理
     * @param $word
     * @return array|mixed|string
     */
    protected function wordsFilter($word, $field = '')
    {
        if (is_array($word)) {
            foreach ($word as $k => $v) {
                $word[$k] = $this->wordsFilter($v);
            }
        } else {
            $word = trim((string)$word);
            foreach (explode('|', $this->cfg['notallowstr']) as $key => $val) {
                if (empty($val)) {
                    continue;
                }
                if (preg_match("/$val/i", $word)) {
                    //过滤掉后台参数设置
                    if (!defined('MANAGE') || defined('MANAGE') && MANAGE != '1') {
                        throw new TextException(21051, ['msg' => $val]);
                    }
                }
            }
            foreach (explode('|', $this->cfg['replacestr']) as $key => $val) {
                if (empty($val)) {
                    continue;
                }
                if (preg_match("/$val/i", $word)) {
                    $word = str_replace($val, '***', $word);
                }
            }
        }
        return $word;
    }

    /**
     * 获取外部提交的数据
     * @param $param
     * @return array
     */
    protected function inputData(array $param): array
    {
        $data = [];
        foreach ($param as $k => $v) {
            $val = $this->getInput($k);
            if (!isset($val) && empty($_FILES[$k]['tmp_name'])) {
                continue;
            }
            if ($v == 'htmltext') {
                $data[$k] = (string)Str::addslashes($val);
            } elseif ($v == 'string') {
                $data[$k] = Str::htmlspecialchars($val);
            } elseif ($v == 'float') {
                $data[$k] = (float)$val;
            } elseif ($v == 'price') {
                $data[$k] = round((float)$val, 2);
            } elseif ($v == 'time') {
                $data[$k] = strtotime($val);
            } elseif (preg_match('/^img/i', $v)) {
                $width = $height = 0;
                if (strpos($v, ',')) {
                    list(, $width, $height) = explode(',', $v);
                }
                $upload = $this->container->get(UploadInterface::class);
                if (is_string($val)) {
                    $res = $upload->h5($val);
                } else {
                    $uploadData = ['files' => $_FILES[$k], 'width' => $width, 'height' => $height];
                    $res = $upload->upload($uploadData);
                }
                if ($res->getCode() != 200 && $res->getCode() != 23001) {
                    throw new TextException($res->getCode());
                }
                $data[$k] = aval($res->getData(), 'fileurl') ?: '';
            } elseif (preg_match('/^int/i', $v)) {
                $data[$k] = (int)$val;
                if (strpos($v, ',')) {
                    list($v, $val1, $val2) = explode(',', $v);
                    if (strpos($v, '==')) {
                        $data[$k] = $val == str_replace('int==', '', $v) ? $val1 : $val2;
                    } else {
                        $data[$k] = $val ? $val1 : $val2;
                    }
                }
            } elseif (preg_match('/^isset/i', $v) && isset($_GET[$k])) {
                $data[$k] = Str::htmlspecialchars($val);
                if (strpos($v, ',')) {
                    list($v, $val1, $val2) = explode(',', $v);
                    if (strpos($v, '==')) {
                        $data[$k] = $val == str_replace('isset==', '', $v) ? $val1 : $val2;
                    } else {
                        $data[$k] = $val ? $val1 : $val2;
                    }
                }
            } elseif (preg_match('/^checkbox/i', $v)) {
                if (strpos($v, ',')) {
                    list(, $func) = explode(',', $v);
                    $data[$k] = implode(',', array_map($func, $val));
                } else {
                    $data[$k] = Str::htmlspecialchars(implode(',', $val));
                }
            } elseif ($v == 'serialize') {
                $data[$k] = $val ? serialize(Str::htmlspecialchars($val)) : '';
            } elseif ($v == 'url') {
                $data[$k] = str_replace(['"', '<', '>', '\'', '(null)', '||', '*', '$', '(', ')'], ['&quot;', '&lt;', '&gt;', '&#039;', '', '&#124;&#124;', '&#042;', '&#036;', '&#040;', ' &#041;'], $val);
            } elseif ($v == 'tel') {
                $data[$k] = preg_replace('/[^\d\-]/i', '', $val);
            } elseif ($v == 'number') {
                $data[$k] = preg_replace('/[^\d]/i', '', $val);
            } elseif ($v == 'anumber') {
                $data[$k] = preg_replace('/[^\d,]/i', '', $val);
            } elseif ($v == 'bnumber') {
                $data[$k] = preg_replace('/[^\d.,`\-\/]/i', '', $val);
            } elseif ($v == 'fnumber') {
                $data[$k] = preg_replace('/[^\d.]/i', '', $val);
            } elseif ($v == 'email') {
                $data[$k] = preg_replace('/[^\w\-.@]/i', '', $val);
            } elseif ($v == 'w') {
                $data[$k] = preg_replace('/[^\w\/]/i', '', $val);
            } elseif ($v == 'str') {
                $data[$k] = preg_replace('/[^\w.,`\-\/]/i', '', $val);
            } elseif ($v == 'date') {
                $data[$k] = preg_replace('/[^\d\-: ]/i', '', $val);
            } elseif ($v == 'media' || $v == 'addon') {
                $upload = $this->container->get(UploadInterface::class);
                $uploadData = is_string($val) ? $val : ['files' => $_FILES[$k], 'type' => $v];
                $res = $upload->upload($uploadData);
                if ($res->getCode() != 200 && $res->getCode() != 23001) {
                    throw new TextException($res->getCode());
                }
                $data[$k] = $res->getData()['fileurl'] ?: '';
            }
        }
        return $data;
    }
}
