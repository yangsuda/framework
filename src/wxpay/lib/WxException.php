<?php
/**
 *
 * 微信支付API异常类
 * @author widyhu
 *
 */

namespace wxpay\lib;

use Exception;

class WxException extends Exception
{
    public function errorMessage()
    {
        return $this->getMessage();
    }
}
