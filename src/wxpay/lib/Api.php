<?php

/**
 *
 * 接口访问类，包含所有微信支付API列表的封装，类中方法为static方法，
 * 每个接口有默认超时时间（除提交被扫支付为10s，上报超时时间为1s外，其他均为6s）
 * @author widyhu
 *
 */

namespace wxpay\lib;

use SlimCMS\Error\TextException;
use SlimCMS\Helper\Ipdata;

class Api
{
    /**
     *
     * 统一下单，UnifiedOrder中out_trade_no、body、total_fee、trade_type必填
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     * @param UnifiedOrder $inputObj
     * @param int $timeOut
     * @return 成功时返回，其他抛异常
     */
    public static function unifiedOrder($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/pay/unifiedorder";
        //检测必填参数
        if (!$inputObj->IsOut_trade_noSet()) {
            throw new TextException(25003, ['msg' => 'out_trade_no'], 'wxpay');
        } else if (!$inputObj->IsBodySet()) {
            throw new TextException(25003, ['msg' => 'body'], 'wxpay');
        } else if (!$inputObj->IsTotal_feeSet()) {
            throw new TextException(25003, ['msg' => 'total_fee'], 'wxpay');
        } else if (!$inputObj->IsTrade_typeSet()) {
            throw new TextException(25003, ['msg' => 'trade_type'], 'wxpay');
        }

        //关联参数
        if ($inputObj->GetTrade_type() == "JSAPI" && !$inputObj->IsOpenidSet()) {
            throw new TextException(25003, ['msg' => 'openid'], 'wxpay');
        }
        if ($inputObj->GetTrade_type() == "NATIVE" && !$inputObj->IsProduct_idSet()) {
            throw new TextException(25003, ['msg' => 'product_id'], 'wxpay');
        }

        //异步通知url未设置，则使用配置文件中的url
        if (!$inputObj->IsNotify_urlSet()) {
            throw new TextException(25004, [], 'wxpay');
        }
        $inputObj->SetAppid(Config::get('appid'));//公众账号ID
        $inputObj->SetMch_id(Config::get('mchid'));//商户号
        $inputObj->SetSpbill_create_ip($_SERVER['REMOTE_ADDR']);//终端ip
        //$inputObj->SetSpbill_create_ip("1.1.1.1");
        $inputObj->SetNonce_str(self::getNonceStr());//随机字符串

        //签名
        $inputObj->SetSign();
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond();//请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = data\Results::Init($response);
        self::reportCostTime($url, $startTimeStamp, $result);//上报请求花费时间

        return $result;
    }

    /**
     *
     * 查询订单，OrderQuery中out_trade_no、transaction_id至少填一个
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     * @param OrderQuery $inputObj
     * @param int $timeOut
     * @return 成功时返回，其他抛异常
     */
    public static function orderQuery($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/pay/orderquery";
        //检测必填参数
        if (!$inputObj->IsOut_trade_noSet() && !$inputObj->IsTransaction_idSet()) {
            throw new TextException(25005, [], 'wxpay');
        }
        $inputObj->SetAppid(Config::get('appid'));//公众账号ID
        $inputObj->SetMch_id(Config::get('mchid'));//商户号
        $inputObj->SetNonce_str(self::getNonceStr());//随机字符串

        $inputObj->SetSign();//签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond();//请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = data\Results::Init($response);
        self::reportCostTime($url, $startTimeStamp, $result);//上报请求花费时间

        return $result;
    }

    /**
     *
     * 关闭订单，CloseOrder中out_trade_no必填
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     * @param CloseOrder $inputObj
     * @param int $timeOut
     * @return 成功时返回，其他抛异常
     */
    public static function closeOrder($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/pay/closeorder";
        //检测必填参数
        if (!$inputObj->IsOut_trade_noSet()) {
            throw new TextException(25006, [], 'wxpay');
        }
        $inputObj->SetAppid(Config::get('appid'));//公众账号ID
        $inputObj->SetMch_id(Config::get('mchid'));//商户号
        $inputObj->SetNonce_str(self::getNonceStr());//随机字符串

        $inputObj->SetSign();//签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond();//请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = data\Results::Init($response);
        self::reportCostTime($url, $startTimeStamp, $result);//上报请求花费时间

        return $result;
    }

    /**
     *
     * 申请退款，Refund中out_trade_no、transaction_id至少填一个且
     * out_refund_no、total_fee、refund_fee、op_user_id为必填参数
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     * @param Refund $inputObj
     * @param int $timeOut
     * @return 成功时返回，其他抛异常
     */
    public static function refund($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/secapi/pay/refund";
        //检测必填参数
        if (!$inputObj->IsOut_trade_noSet() && !$inputObj->IsTransaction_idSet()) {
            throw new TextException(25007, ['msg' => 'out_trade_no、transaction_id至少填一个'], 'wxpay');
        } else if (!$inputObj->IsOut_refund_noSet()) {
            throw new TextException(25007, ['msg' => 'out_refund_no'], 'wxpay');
        } else if (!$inputObj->IsTotal_feeSet()) {
            throw new TextException(25007, ['msg' => 'total_fee'], 'wxpay');
        } else if (!$inputObj->IsRefund_feeSet()) {
            throw new TextException(25007, ['msg' => 'refund_fee'], 'wxpay');
        } else if (!$inputObj->IsOp_user_idSet()) {
            throw new TextException(25007, ['msg' => 'op_user_id'], 'wxpay');
        }
        $inputObj->SetAppid(Config::get('appid'));//公众账号ID
        $inputObj->SetMch_id(Config::get('mchid'));//商户号
        $inputObj->SetNonce_str(self::getNonceStr());//随机字符串

        $inputObj->SetSign();//签名
        $xml = $inputObj->ToXml();
        $startTimeStamp = self::getMillisecond();//请求开始时间
        $response = self::postXmlCurl($xml, $url, true, $timeOut);
        $result = data\Results::Init($response);
        self::reportCostTime($url, $startTimeStamp, $result);//上报请求花费时间

        return $result;
    }

    /**
     *
     * 查询退款
     * 提交退款申请后，通过调用该接口查询退款状态。退款有一定延时，
     * 用零钱支付的退款20分钟内到账，银行卡支付的退款3个工作日后重新查询退款状态。
     * RefundQuery中out_refund_no、out_trade_no、transaction_id、refund_id四个参数必填一个
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     * @param RefundQuery $inputObj
     * @param int $timeOut
     * @return 成功时返回，其他抛异常
     */
    public static function refundQuery($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/pay/refundquery";
        //检测必填参数
        if (!$inputObj->IsOut_refund_noSet() &&
            !$inputObj->IsOut_trade_noSet() &&
            !$inputObj->IsTransaction_idSet() &&
            !$inputObj->IsRefund_idSet()) {
            throw new TextException(25007, ['msg' => 'out_refund_no、out_trade_no、transaction_id、refund_id四个参数必填一个'], 'wxpay');
        }
        $inputObj->SetAppid(Config::get('appid'));//公众账号ID
        $inputObj->SetMch_id(Config::get('mchid'));//商户号
        $inputObj->SetNonce_str(self::getNonceStr());//随机字符串

        $inputObj->SetSign();//签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond();//请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = data\Results::Init($response);
        self::reportCostTime($url, $startTimeStamp, $result);//上报请求花费时间

        return $result;
    }

    /**
     * 下载对账单，DownloadBill中bill_date为必填参数
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     * @param DownloadBill $inputObj
     * @param int $timeOut
     * @return 成功时返回，其他抛异常
     */
    public static function downloadBill($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/pay/downloadbill";
        //检测必填参数
        if (!$inputObj->IsBill_dateSet()) {
            throw new TextException(25008, [], 'wxpay');
        }
        $inputObj->SetAppid(Config::get('appid'));//公众账号ID
        $inputObj->SetMch_id(Config::get('mchid'));//商户号
        $inputObj->SetNonce_str(self::getNonceStr());//随机字符串

        $inputObj->SetSign();//签名
        $xml = $inputObj->ToXml();

        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        if (substr($response, 0, 5) == "<xml>") {
            return "";
        }
        return $response;
    }

    /**
     * 提交被扫支付API
     * 收银员使用扫码设备读取微信用户刷卡授权码以后，二维码或条码信息传送至商户收银台，
     * 由商户收银台或者商户后台调用该接口发起支付。
     * MicroPay中body、out_trade_no、total_fee、auth_code参数必填
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     * @param MicroPay $inputObj
     * @param int $timeOut
     */
    public static function micropay($inputObj, $timeOut = 10)
    {
        $url = "https://api.mch.weixin.qq.com/pay/micropay";
        //检测必填参数
        if (!$inputObj->IsBodySet()) {
            throw new TextException(25009, ['msg' => 'body'], 'wxpay');
        } else if (!$inputObj->IsOut_trade_noSet()) {
            throw new TextException(25009, ['msg' => 'out_trade_no'], 'wxpay');
        } else if (!$inputObj->IsTotal_feeSet()) {
            throw new TextException(25009, ['msg' => 'total_fee'], 'wxpay');
        } else if (!$inputObj->IsAuth_codeSet()) {
            throw new TextException(25009, ['msg' => 'auth_code'], 'wxpay');
        }

        $inputObj->SetSpbill_create_ip($_SERVER['REMOTE_ADDR']);//终端ip
        $inputObj->SetAppid(Config::get('appid'));//公众账号ID
        $inputObj->SetMch_id(Config::get('mchid'));//商户号
        $inputObj->SetNonce_str(self::getNonceStr());//随机字符串

        $inputObj->SetSign();//签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond();//请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = data\Results::Init($response);
        self::reportCostTime($url, $startTimeStamp, $result);//上报请求花费时间

        return $result;
    }

    /**
     *
     * 撤销订单API接口，Reverse中参数out_trade_no和transaction_id必须填写一个
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     * @param Reverse $inputObj
     * @param int $timeOut
     */
    public static function reverse($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/secapi/pay/reverse";
        //检测必填参数
        if (!$inputObj->IsOut_trade_noSet() && !$inputObj->IsTransaction_idSet()) {
            throw new TextException(25010, [], 'wxpay');
        }

        $inputObj->SetAppid(Config::get('appid'));//公众账号ID
        $inputObj->SetMch_id(Config::get('mchid'));//商户号
        $inputObj->SetNonce_str(self::getNonceStr());//随机字符串

        $inputObj->SetSign();//签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond();//请求开始时间
        $response = self::postXmlCurl($xml, $url, true, $timeOut);
        $result = data\Results::Init($response);
        self::reportCostTime($url, $startTimeStamp, $result);//上报请求花费时间

        return $result;
    }

    /**
     *
     * 测速上报，该方法内部封装在report中，使用时请注意异常流程
     * WxPayReport中interface_url、return_code、result_code、user_ip、execute_time_必填
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     * @param WxPayReport $inputObj
     * @param int $timeOut
     * @return 成功时返回，其他抛异常
     */
    public static function report($inputObj, $timeOut = 1)
    {
        $url = "https://api.mch.weixin.qq.com/payitil/report";
        //检测必填参数
        if (!$inputObj->IsInterface_urlSet()) {
            throw new TextException(25011, ['msg' => 'interface_url'], 'wxpay');
        }
        if (!$inputObj->IsReturn_codeSet()) {
            throw new TextException(25011, ['msg' => 'return_code'], 'wxpay');
        }
        if (!$inputObj->IsResult_codeSet()) {
            throw new TextException(25011, ['msg' => 'result_code'], 'wxpay');
        }
        if (!$inputObj->IsUser_ipSet()) {
            throw new TextException(25011, ['msg' => 'user_ip'], 'wxpay');
        }
        if (!$inputObj->IsExecute_time_Set()) {
            throw new TextException(25011, ['msg' => 'execute_time'], 'wxpay');
        }
        $inputObj->SetAppid(Config::get('appid'));//公众账号ID
        $inputObj->SetMch_id(Config::get('mchid'));//商户号
        $inputObj->SetUser_ip($_SERVER['REMOTE_ADDR']);//终端ip
        $inputObj->SetTime(date("YmdHis"));//商户上报时间
        $inputObj->SetNonce_str(self::getNonceStr());//随机字符串

        $inputObj->SetSign();//签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond();//请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        return $response;
    }

    /**
     *
     * 生成二维码规则,模式一生成支付二维码
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     * @param BizPayUrl $inputObj
     * @param int $timeOut
     * @return 成功时返回，其他抛异常
     */
    public static function bizpayurl($inputObj, $timeOut = 6)
    {
        if (!$inputObj->IsProduct_idSet()) {
            throw new TextException(25012, [], 'wxpay');
        }

        $inputObj->SetAppid(Config::get('appid'));//公众账号ID
        $inputObj->SetMch_id(Config::get('mchid'));//商户号
        $inputObj->SetTime_stamp(time());//时间戳
        $inputObj->SetNonce_str(self::getNonceStr());//随机字符串

        $inputObj->SetSign();//签名

        return $inputObj->GetValues();
    }

    /**
     *
     * 转换短链接
     * 该接口主要用于扫码原生支付模式一中的二维码链接转成短链接(weixin://wxpay/s/XXXXXX)，
     * 减小二维码数据量，提升扫描速度和精确度。
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     * @param ShortUrl $inputObj
     * @param int $timeOut
     * @return 成功时返回，其他抛异常
     */
    public static function shorturl($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/tools/shorturl";
        //检测必填参数
        if (!$inputObj->IsLong_urlSet()) {
            throw new TextException(25013, [], 'wxpay');
        }
        $inputObj->SetAppid(Config::get('appid'));//公众账号ID
        $inputObj->SetMch_id(Config::get('mchid'));//商户号
        $inputObj->SetNonce_str(self::getNonceStr());//随机字符串

        $inputObj->SetSign();//签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond();//请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = data\Results::Init($response);
        self::reportCostTime($url, $startTimeStamp, $result);//上报请求花费时间

        return $result;
    }

    /**
     *
     * 支付结果通用通知
     * @param function $callback
     * 直接回调函数使用方法: notify(you_function);
     * 回调类成员函数方法:notify(array($this, you_function));
     * $callback  原型为：function function_name($data){}
     */
    public static function notify($callback, &$msg)
    {
        //获取通知的数据
        $xml = @file_get_contents("php://input");
        //如果返回成功则验证签名
        try {
            $result = data\Results::Init($xml);
        } catch (WxException $e) {
            $msg = $e->errorMessage();
            return false;
        }

        return call_user_func($callback, $result);
    }

    /**
     *
     * 产生随机字符串，不长于32位
     * @param int $length
     * @return 产生的随机字符串
     */
    public static function getNonceStr($length = 32)
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }

    /**
     * 直接输出xml
     * @param string $xml
     */
    public static function replyNotify($xml)
    {
        echo $xml;
    }

    /**
     *
     * 上报数据， 上报的时候将屏蔽所有异常流程
     * @param string $usrl
     * @param int $startTimeStamp
     * @param array $data
     */
    private static function reportCostTime($url, $startTimeStamp, $data)
    {
        //如果不需要上报数据
        if (Config::get('report_levenl') == 0) {
            return;
        }
        //如果仅失败上报
        if (Config::get('report_levenl') == 1 &&
            array_key_exists("return_code", $data) &&
            $data["return_code"] == "SUCCESS" &&
            array_key_exists("result_code", $data) &&
            $data["result_code"] == "SUCCESS") {
            return;
        }

        //上报逻辑
        $endTimeStamp = self::getMillisecond();
        $objInput = new data\Report();
        $objInput->SetInterface_url($url);
        $objInput->SetExecute_time_($endTimeStamp - $startTimeStamp);
        //返回状态码
        if (array_key_exists("return_code", $data)) {
            $objInput->SetReturn_code($data["return_code"]);
        }
        //返回信息
        if (array_key_exists("return_msg", $data)) {
            $objInput->SetReturn_msg($data["return_msg"]);
        }
        //业务结果
        if (array_key_exists("result_code", $data)) {
            $objInput->SetResult_code($data["result_code"]);
        }
        //错误代码
        if (array_key_exists("err_code", $data)) {
            $objInput->SetErr_code($data["err_code"]);
        }
        //错误代码描述
        if (array_key_exists("err_code_des", $data)) {
            $objInput->SetErr_code_des($data["err_code_des"]);
        }
        //商户订单号
        if (array_key_exists("out_trade_no", $data)) {
            $objInput->SetOut_trade_no($data["out_trade_no"]);
        }
        //设备号
        if (array_key_exists("device_info", $data)) {
            $objInput->SetDevice_info($data["device_info"]);
        }

        try {
            self::report($objInput);
        } catch (WxException $e) {
            //不做任何处理
        }
    }

    /**
     * 以post方式提交xml到对应的接口url
     *
     * @param string $xml 需要post的xml数据
     * @param string $url url
     * @param bool $useCert 是否需要证书，默认不需要
     * @param int $second url执行超时时间，默认30s
     */
    private static function postXmlCurl($xml, $url, $useCert = false, $second = 30)
    {
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);

        //如果有配置代理这里就设置代理
        if (Config::get('curl_proxy_host') != "0.0.0.0" && Config::get('curl_proxy_port') != 0) {
            curl_setopt($ch, CURLOPT_PROXY, Config::get('curl_proxy_host'));
            curl_setopt($ch, CURLOPT_PROXYPORT, Config::get('curl_proxy_port'));
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        //设置header
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        if ($useCert == true) {
            //设置证书
            //使用证书：cert 与 key 分别属于两个.pem文件
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLCERT, Config::get('sslcert_path'));
            curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLKEY, Config::get('sslkey_path'));
        }
        //post提交方式
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        //运行curl
        $data = curl_exec($ch);
        //返回结果
        if ($data) {
            curl_close($ch);
            return $data;
        } else {
            $error = curl_errno($ch);
            curl_close($ch);
            throw new TextException(25014, ['msg' => $error], 'wxpay');
        }
    }

    /**
     * 获取毫秒级别的时间戳
     */
    private static function getMillisecond()
    {
        //获取毫秒的时间戳
        $time = explode(" ", microtime());
        $time = $time[1] . ($time[0] * 1000);
        $time2 = explode(".", $time);
        $time = $time2[0];
        return $time;
    }

    /**
     * 企业向微信用户个人付款URL
     * @param $inputObj
     * @param int $timeOut
     * @return mixed
     */
    public static function transfers($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers";
        //检测必填参数
        if (!$inputObj->getValue('openid') && !$inputObj->getValue('amount') && !$inputObj->getValue('desc')) {
            throw new TextException(21000, ['msg' => 'openid、amount、desc必填'], 'wxpay');
        }
        $inputObj->setValue('mch_appid', Config::get('appid'));//公众账号ID
        $inputObj->setValue('mchid', Config::get('mchid'));//商户号
        $inputObj->setValue('nonce_str', self::getNonceStr());//随机字符串
        !$inputObj->getValue('partner_trade_no') && $inputObj->setValue('partner_trade_no', Config::get('mchid') . TIMESTAMP . mt_rand(10000000, 99999999));
        !$inputObj->getValue('check_name') && $inputObj->setValue('check_name', 'NO_CHECK');
        !$inputObj->getValue('spbill_create_ip') && $inputObj->setValue('spbill_create_ip', Ipdata::getip());
        $inputObj->SetSign();//签名
        $xml = $inputObj->ToXml();

        $response = self::postXmlCurl($xml, $url, true, $timeOut);
        return data\Results::Init($response);
    }

    /**
     * 企业向微信用户个人发红包
     * @param $inputObj
     * @param int $timeOut
     * @return mixed
     */
    public static function sendRedpack($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/mmpaymkttransfers/sendredpack";
        //检测必填参数
        if (
            !$inputObj->getValue('re_openid') &&
            !$inputObj->getValue('total_amount') &&
            !$inputObj->getValue('send_name') &&
            !$inputObj->getValue('total_num') &&
            !$inputObj->getValue('wishing') &&
            !$inputObj->getValue('act_name') &&
            !$inputObj->getValue('remark')
        ) {
            throw new TextException(21003, [], 'wxpay');
        }
        $inputObj->setValue('wxappid', Config::get('appid'));//公众账号ID
        $inputObj->setValue('mch_id', Config::get('mchid'));//商户号
        $inputObj->setValue('nonce_str', self::getNonceStr());//随机字符串
        !$inputObj->getValue('mch_billno') && $inputObj->setValue('mch_billno', Config::get('mchid') . TIMESTAMP . mt_rand(10000000, 99999999));
        $inputObj->setValue('client_ip', Ipdata::getip());
        $inputObj->SetSign();//签名
        $xml = $inputObj->ToXml();

        $response = self::postXmlCurl($xml, $url, true, $timeOut);
        return data\Results::Init($response);
    }
}

