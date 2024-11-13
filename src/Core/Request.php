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
            if (strpos($v, '*')) {
                list($v, $title) = explode('*', $v);
                if (empty($val)) {
                    $msg = $title ? $title . '必填' : '必填参数不能为空(' . $k . ')';
                    throw new TextException(21000, ['msg' => $msg]);
                }
            }
            if (!isset($val) && empty($_FILES[$k]['tmp_name'])) {
                continue;
            }
            switch ($v) {
                case 'htmltext':
                    $data[$k] = (string)Str::addslashes($val);
                    break;
                case 'string':
                    $data[$k] = Str::htmlspecialchars($val);
                    break;
                case 'float':
                    $data[$k] = (float)$val;
                    break;
                case 'price':
                    $data[$k] = round((float)$val, 2);
                    break;
                case 'time':
                    $data[$k] = strtotime($val);
                    break;
                case 'serialize':
                    $data[$k] = $val ? serialize(Str::htmlspecialchars($val)) : '';
                    break;
                case 'url':
                    $data[$k] = str_replace(
                        ['"', '<', '>', '\'', '(null)', '||', '*', '$', '(', ')'],
                        ['&quot;', '&lt;', '&gt;', '&#039;', '', '&#124;&#124;', '&#042;', '&#036;', '&#040;', ' &#041;'],
                        $val);
                    break;
                case 'tel':
                    $data[$k] = preg_replace('/[^\d\-]/i', '', $val);
                    break;
                case 'number':
                    $data[$k] = preg_replace('/[^\d]/i', '', $val);
                    break;
                case 'anumber':
                    $data[$k] = preg_replace('/[^\d,]/i', '', $val);
                    break;
                case 'bnumber':
                    $data[$k] = preg_replace('/[^\d.,`\-\/]/i', '', $val);
                    break;
                case 'fnumber':
                    $data[$k] = preg_replace('/[^\d.]/i', '', $val);
                    break;
                case 'email':
                    $data[$k] = preg_replace('/[^\w\-.@]/i', '', $val);
                    break;
                case 'w':
                    $data[$k] = preg_replace('/[^\w\/]/i', '', $val);
                    break;
                case 'str':
                    $data[$k] = preg_replace('/[^\w.,`\-\/]/i', '', $val);
                    break;
                case 'date':
                    $data[$k] = preg_replace('/[^\d\-: ]/i', '', $val);
                    break;
                case 'media':
                case 'addon':
                    $upload = $this->container->get(UploadInterface::class);
                    $uploadData = is_string($val) ? $val : ['files' => $_FILES[$k], 'type' => $v];
                    $res = $upload->upload($uploadData);
                    if ($res->getCode() != 200 && $res->getCode() != 23001) {
                        throw new TextException($res->getCode());
                    }
                    $data[$k] = $res->getData()['fileurl'] ?: '';
                    break;
                default:
                    if (preg_match('/^int/i', $v)) {
                        $data[$k] = (int)$val;
                        if (strpos($v, ',')) {
                            list($v, $val1, $val2) = explode(',', $v);
                            if (strpos($v, '==')) {
                                $data[$k] = $val == str_replace('int==', '', $v) ? $val1 : $val2;
                            } else {
                                $data[$k] = $val ? $val1 : $val2;
                            }
                        }
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
                    } elseif (preg_match('/^list:/i', $v)) {//接收列表中指定数据
                        $val = (string)$val;
                        $arr = [];
                        if ($val) {
                            $whiteList = explode(',', str_replace('list:', '', $v));
                            foreach (explode(',', $val) as $v1) {
                                if (in_array($v1, $whiteList)) {
                                    $arr[] = $v1;
                                }
                            }
                        }
                        $data[$k] = $arr;
                    }
                    break;
            }
        }
        return $data;
    }
}
