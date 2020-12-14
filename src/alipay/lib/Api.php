<?php
/* *
 * 类名：AlipaySubmit
 * 功能：支付宝各接口请求提交类
 * 详细：构造支付宝各接口表单HTML文本，获取远程HTTP数据
 * 版本：3.3
 */

namespace alipay\lib;

class Api
{
    /**
     *支付宝网关地址（新）
     */
    private static $alipay_gateway_new = 'https://mapi.alipay.com/gateway.do?';

    /**
     * 生成签名结果
     * @param $para_sort 已排序要签名的数组
     * return 签名结果字符串
     */
    private static function buildRequestMysign($para_sort)
    {
        //把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
        $prestr = Functions::createLinkstring($para_sort);

        switch (strtoupper(Config::get('sign_type'))) {
            case "RSA" :
                $mysign = Functions::rsaSign($prestr, Config::get('private_path'));
                break;
            default :
                $mysign = '';
        }

        return $mysign;
    }

    /**
     * 生成要请求给支付宝的参数数组
     * @param $para_temp 请求前的参数数组
     * @return 要请求的参数数组
     */
    private static function buildRequestPara($para_temp)
    {
        //除去待签名参数数组中的空值和签名参数
        $para_filter = Functions::paraFilter($para_temp);

        //对待签名参数数组排序
        $para_sort = Functions::argSort($para_filter);

        //生成签名结果
        $mysign = self::buildRequestMysign($para_sort);

        //签名结果与签名方式加入请求提交参数组中
        $para_sort['sign'] = $mysign;
        $para_sort['sign_type'] = strtoupper(Config::get('sign_type'));

        return $para_sort;
    }

    /**
     * 生成要请求给支付宝的参数数组
     * @param $para_temp 请求前的参数数组
     * @return 要请求的参数数组字符串
     */
    private static function buildRequestParaToString($para_temp)
    {
        //待请求参数数组
        $para = self::buildRequestPara($para_temp);
        //把参数组中所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串，并对字符串做urlencode编码
        $request_data = Functions::createLinkstringUrlencode($para);

        return $request_data;
    }

    public static function getRequestURL($para_temp)
    {
        $request_data = self::buildRequestParaToString($para_temp);
        return self::$alipay_gateway_new . $request_data;
    }

    /**
     * 建立请求，以表单HTML形式构造（默认）
     * @param $para_temp 请求参数数组
     * @param $method 提交方式。两个值可选：post、get
     * @param $button_name 确认按钮显示文字
     * @return 提交表单HTML文本
     */
    public static function buildRequestForm($para_temp, $method, $button_name)
    {
        //待请求参数数组
        $para = self::buildRequestPara($para_temp);

        $sHtml = "<form id='alipaysubmit' name='alipaysubmit' action='" . self::$alipay_gateway_new . "_input_charset=" . Config::get('input_charset') . "' method='" . $method . "'>";
        foreach ($para as $k=>$v){
            $sHtml .= "<input type='hidden' name='" . $k . "' value='" . $v . "'/>";
        }

        //submit按钮控件请不要含有name属性
        $sHtml = $sHtml . "<input type='submit' value='" . $button_name . "'></form>";
        $sHtml = $sHtml . "<script>document.forms['alipaysubmit'].submit();</script>";
        return $sHtml;
    }

    /**
     * 建立请求，以模拟远程HTTP的POST请求方式构造并获取支付宝的处理结果
     * @param $para_temp 请求参数数组
     * @return 支付宝处理结果
     */
    public static function buildRequestHttp($para_temp)
    {
        //待请求参数数组字符串
        $request_data = self::buildRequestPara($para_temp);

        //远程获取数据
        $sResult = Functions::getHttpResponsePOST(self::$alipay_gateway_new, Config::get('alicacert'), $request_data, strtolower(Config::get('input_charset')));

        return $sResult;
    }

    /**
     * 建立请求，以模拟远程HTTP的POST请求方式构造并获取支付宝的处理结果，带文件上传功能
     * @param $para_temp 请求参数数组
     * @param $file_para_name 文件类型的参数名
     * @param $file_name 文件完整绝对路径
     * @return 支付宝返回处理结果
     */
    public static function buildRequestHttpInFile($para_temp, $file_para_name, $file_name)
    {

        //待请求参数数组
        $para = self::buildRequestPara($para_temp);
        $para[$file_para_name] = "@" . $file_name;

        //远程获取数据
        $sResult = Functions::getHttpResponsePOST(self::$alipay_gateway_new, Config::get('alicacert'), $para, strtolower(Config::get('input_charset')));

        return $sResult;
    }

    /**
     * 用于防钓鱼，调用接口query_timestamp来获取时间戳的处理函数
     * 注意：该功能PHP5环境及以上支持，因此必须服务器、本地电脑中装有支持DOMDocument、SSL的PHP配置环境。建议本地调试时使用PHP开发软件
     * return 时间戳字符串
     */
    public static function query_timestamp()
    {
        $url = self::$alipay_gateway_new . "service=query_timestamp&partner=" . strtolower(Config::get('partner')) . "&_input_charset=" . strtolower(Config::get('input_charset'));
        $doc = new \DOMDocument();
        $doc->load($url);
        $itemEncrypt_key = $doc->getElementsByTagName("encrypt_key");
        $encrypt_key = $itemEncrypt_key->item(0)->nodeValue;
        return $encrypt_key;
    }
}