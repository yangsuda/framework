<?php

/**
 *
 * 接口调用结果类
 * @author widyhu
 *
 */

namespace wxpay\lib\data;

use SlimCMS\Error\TextException;

class Results extends Data
{

    /**
     *
     * 检测签名
     */
    public function CheckSign()
    {
        if (!$this->IsSignSet()) {
            return true;
        }

        $sign = $this->MakeSign();
        if ($this->GetSign() == $sign) {
            return true;
        }
        throw new TextException(25002, [], 'wxpay');
    }

    /**
     *
     * 使用数组初始化
     * @param array $array
     */
    public function FromArray($array)
    {
        $this->values = $array;
    }

    /**
     *
     * 使用数组初始化对象
     * @param array $array
     * @param 是否检测签名 $noCheckSign
     */
    public static function InitFromArray($array, $noCheckSign = false)
    {
        $obj = new self();
        $obj->FromArray($array);
        if ($noCheckSign == false) {
            $obj->CheckSign();
        }
        return $obj;
    }

    /**
     *
     * 设置参数
     * @param string $key
     * @param string $value
     */
    public function SetData($key, $value)
    {
        $this->values[$key] = $value;
    }

    /**
     * 将xml转为array
     * @param string $xml
     */
    public static function Init($xml)
    {
        $obj = new self();
        $obj->FromXml($xml);
        $obj->CheckSign();
        return $obj->GetValues();
    }
}
