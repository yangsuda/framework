<?php
/**
 * 微信支付类
 * @author zhucy
 * @date 2020.03.24
 */

namespace wxpay;

use SlimCMS\Helper\File;
use SlimCMS\Interfaces\OutputInterface;

class Wxpay
{
    private $output = '';
    private $data = [];

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
        $this->data = $output->getData();
        lib\Config::set($this->data);
        parent::__construct();
    }

    /**
     * 微信支付
     * @param $param
     * @return array
     */
    public function pay(): OutputInterface
    {
        $body = aval($this->data, 'body');
        $out_trade_no = aval($this->data, 'out_trade_no');
        $total_fee = aval($this->data, 'total_fee');
        $notify_url = aval($this->data, 'notify_url');
        $openid = aval($this->data, 'openid');
        $appid = aval($this->data, 'appid');
        $appsecret = aval($this->data, 'appsecret');
        $mchid = aval($this->data, 'mchid');
        $trade_type = aval($this->data, 'trade_type') ?: 'JSAPI';
        $attach = aval($this->data, 'attach');
        $notify = aval($this->data, 'notify');
        if (!$body || !$out_trade_no || !$total_fee || !$notify_url || !$openid || !$appid || !$appsecret || !$mchid || !$notify) {
            return $this->output->withCode(21003);
        }
        $input = new lib\data\UnifiedOrder();
        $input->SetBody(mb_substr($body, 0, 40, 'utf-8'));
        $input->SetOut_trade_no($out_trade_no);
        $input->SetTotal_fee($total_fee);
        $input->SetNotify_url($notify_url);
        $input->SetTrade_type($trade_type);
        $input->SetOpenid($openid);
        $attach && $input->SetAttach($attach);
        $res = lib\Api::unifiedOrder($input);
        File::log('wx/paylog/pay')->info('支付单号' . $out_trade_no . '支付下单接口响应内容', $res);
        if ($res['return_code'] == 'FAIL') {
            return $this->output->withCode(21000, ['msg' => $res['return_msg']]);
        }
        $jsapi = new lib\JsApiPay();
        $jsApiParameters = $jsapi->GetJsApiParameters($res);
        $jsApiParameters = json_decode($jsApiParameters, true);
        File::log('wx/paylog/pay')->info('支付单号' . $out_trade_no . 'jsApi参数', $jsApiParameters);
        return $this->output->withCode(200)->withData($jsApiParameters);
    }

    /**
     * 微信异步回调
     */
    public function notify()
    {
        $xmlStr = @file_get_contents("php://input");
        File::log('wx/paylog/notify')->info('异步回调:' . $_SERVER['REQUEST_URI'], ['xml' => $xmlStr]);
        $msg = "OK";
        return lib\Api::notify(array($this, 'wxNotify'), $msg);
    }

    /**
     * 回调逻辑处理函数
     * @param type $data
     * @return type
     */
    public function wxNotify($data): OutputInterface
    {
        $notify = aval($this->data, 'notify');
        File::log('wx/paylog/notify')->info('异步回调', $data);
        if ($data['result_code'] == 'SUCCESS' && $data['return_code'] == 'SUCCESS') {
            $msg = "<xml>
                        <return_code><![CDATA[SUCCESS]]></return_code>
                        <return_msg><![CDATA[OK]]></return_msg>
                        </xml>";
            $notify && call_user_func($notify, $data);
            return $this->output->withCode(200, $msg);
        }
        $msg = "<xml>
                        <return_code><![CDATA[FAIL]]></return_code>
                        <return_msg><![CDATA[]]></return_msg>
                        </xml>";
        return $this->output->withCode(101, $msg);
    }

    /**
     * 微信退款
     * @return array
     */
    public function refund(): OutputInterface
    {
        $out_refund_no = aval($this->data, 'out_refund_no');
        $out_trade_no = aval($this->data, 'out_trade_no');
        $total_fee = aval($this->data, 'total_fee');
        $transaction_id = aval($this->data, 'transaction_id');
        $refund_fee = aval($this->data, 'refund_fee');
        $mchid = aval($this->data, 'mchid');
        if (!$out_refund_no || !$out_trade_no || !$total_fee || !$transaction_id || !$refund_fee || !$mchid) {
            return $this->output->withCode(21003);
        }
        $input = new lib\data\Refund();
        $input->SetOut_refund_no($out_refund_no);
        $input->SetOut_trade_no($out_trade_no);
        $input->SetTransaction_id($transaction_id);
        $input->SetTotal_fee($total_fee);
        $input->SetRefund_fee($refund_fee);
        $input->SetOp_user_id($mchid);
        $res = lib\Api::refund($input);
        File::log('wx/paylog/refund')->info('退款单号' . $out_refund_no, $res);
        if ($res['return_code'] == 'FAIL') {
            return $this->output->withCode(21000, ['msg' => $res['return_msg']]);
        }
        return $this->output->withCode(200, 21017)->withData($res);
    }

    /**
     * 退款状态查询
     * @return array
     */
    public function refundQuery(): OutputInterface
    {
        $refundid = aval($this->data, 'refundid');
        $refundorder = aval($this->data, 'refundorder');
        if (!$refundid || !$refundorder) {
            return $this->output->withCode(21003);
        }
        $input = new lib\data\RefundQuery();
        $input->SetRefund_id($refundid);
        $res = lib\Api::refundQuery($input);
        if ($res['return_code'] == 'FAIL') {
            File::log('wx/paylog/refundQuery')->info('退款查询' . $refundorder, $res);
            return $this->output->withCode(21000, ['msg' => $res['return_msg']]);
        }
        return $this->output->withCode(200, 21017)->withData($res);
    }

    /**
     * 企业向微信用户个人零钱付款
     */
    public function transfers(): OutputInterface
    {
        $openid = aval($this->data, 'openid');
        $amount = aval($this->data, 'amount');
        $desc = aval($this->data, 'desc');
        if (!$openid || !$amount || !$desc) {
            return $this->output->withCode(21003);
        }
        $input = new lib\data\Data();
        $input->setValue('openid', $openid);
        $input->setValue('amount', $amount);
        $input->setValue('desc', $desc);
        aval($this->data, 're_user_name') && $input->setValue('re_user_name', aval($this->data, 're_user_name'));
        $res = lib\Api::transfers($input);
        File::log('wx/paylog/transfers')->info('企业向个人零钱付款', $res);
        if ($res['return_code'] == 'FAIL') {
            return $this->output->withCode(21000, ['msg' => $res['return_msg']]);
        }
        return $this->output->withCode(200, 21017)->withData($res);
    }

    /**
     * 生成直接支付url，支付url有效期为2小时,模式二
     */
    public function GetPayUrl(): OutputInterface
    {
        $body = aval($this->data, 'body');
        $out_trade_no = aval($this->data, 'out_trade_no');
        $total_fee = aval($this->data, 'total_fee');
        $notify_url = aval($this->data, 'notify_url');
        $product_id = aval($this->data, 'product_id');
        if (!$body || !$out_trade_no || !$total_fee || !$notify_url || !$product_id) {
            return $this->output->withCode(21003);
        }
        $trade_type = aval($this->data, 'trade_type', 'NATIVE');
        $input = new lib\data\UnifiedOrder();
        $input->SetBody(mb_substr($body, 0, 40, 'utf-8'));
        $input->SetOut_trade_no($out_trade_no);
        $input->SetTotal_fee($total_fee);
        $input->SetNotify_url($notify_url);
        $input->SetTrade_type($trade_type);
        $input->SetProduct_id($product_id);
        $obj = new lib\NativePay();
        $res = $obj->GetPayUrl($input);
        File::log('wx/paylog/GetPayUrl')->info('(pc)支付单号' . $out_trade_no, $res);
        $data = ['code_url' => $res['code_url']];
        return $this->output->withCode(200)->withData($data);
    }

    /**
     * 企业向微信用户个人发红包
     */
    public function sendRedpack(): OutputInterface
    {
        $openid = aval($this->data, 'openid');
        $amount = aval($this->data, 'amount');
        $send_name = aval($this->data, 'send_name');
        $total_num = aval($this->data, 'total_num');
        $wishing = aval($this->data, 'wishing');
        $act_name = aval($this->data, 'act_name');
        $remark = aval($this->data, 'remark');
        if (!$openid || !$amount || !$send_name || !$total_num || !$wishing || !$act_name || !$remark) {
            return $this->output->withCode(21003);
        }
        $input = new lib\data\Data();
        $input->setValue('re_openid', $openid);
        $input->setValue('total_amount', $amount);
        $input->setValue('send_name', $send_name);
        $input->setValue('total_num', $total_num);
        $input->setValue('wishing', $wishing);
        $input->setValue('act_name', $act_name);
        $input->setValue('remark', $remark);
        $res = lib\Api::sendRedpack($input);
        File::log('wx/paylog/sendRedpack')->info('(企业向个人发红包', $res);
        if ($res['return_code'] == 'FAIL') {
            return $this->output->withCode(21000, ['msg' => $res['return_msg']]);
        }
        return $this->output->withCode(200, 21017)->withData($res);
    }
}
