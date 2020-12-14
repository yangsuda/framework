<?php
/**
 * 支付宝支付类
 * @author zhucy
 * @date 2020.06.01
 */

namespace alipay;

use SlimCMS\Helper\File;
use SlimCMS\Interfaces\OutputInterface;

class Alipay
{
    private $output = '';
    private $data = [];

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
        $this->data = $output->getData();
        lib\Config::set($this->obj->payConfig);
        parent::__construct();
    }

    /**
     * 支付
     */
    public function pay(): OutputInterface
    {
        $service = aval($this->data, 'service');
        $subject = aval($this->data, 'subject');
        $show_url = aval($this->data, 'show_url');
        $out_trade_no = aval($this->data, 'out_trade_no');
        $total_fee = aval($this->data, 'total_fee');
        $notify_url = aval($this->data, 'notify_url');
        $return_url = aval($this->data, 'return_url');
        $partner = aval($this->data, 'partner');
        $input_charset = aval($this->data, 'input_charset');
        $seller_email = aval($this->data, 'seller_email');
        $extra_common_param = aval($this->data, 'extra_common_param');
        if (!$service || !$subject || !$show_url || !$out_trade_no || !$total_fee || !$notify_url || !$return_url || !$partner) {
            return $this->output->withCode(21003);
        }
        $parameter = [
            "service" => $service,
            "partner" => $partner,
            "seller_id" => $partner,
            "_input_charset" => $input_charset,
            "payment_type" => "1",
            "seller_email" => $seller_email,
            'subject' => $subject,
            'out_trade_no' => $out_trade_no,
            'total_fee' => $total_fee,
            'show_url' => $show_url,
            'notify_url' => $notify_url,
            'return_url' => $return_url,
        ];
        $extra_common_param && $parameter['extra_common_param'] = $extra_common_param;
        $reqUrl = lib\Api::getRequestURL($parameter);
        $data = ['url' => $reqUrl];
        return $this->output->withCode(200)->withData($data);
    }

    /**
     * 异步回调
     */
    public function notify(): OutputInterface
    {
        //回调日志
        File::log('alipay/paylog/notify')->info('异步回调:' . $_SERVER['REQUEST_URI'], $_POST);
        //计算得出通知验证结果
        $verify_result = lib\Notify::verifyNotify();
        if ($verify_result) {
            $notify = aval($this->data, 'notify');
            $notify && call_user_func($notify, $_POST);
            return $this->output->withCode(200, 100);
        }
        return $this->output->withCode(101);
    }

    /**
     * 同步回调
     */
    public function returnDo(): OutputInterface
    {
        //回调日志
        File::log('alipay/paylog/return')->info('同步回调:' . $_SERVER['REQUEST_URI'], $_GET);

        //计算得出通知验证结果
        $verify_result = lib\Notify::verifyReturn();
        if ($verify_result) {
            $returnDo = aval($this->data, 'returnDo');
            $returnDo && call_user_func($returnDo, $_GET);
        }
        return $this->output->withCode(21000, ['msg' => aval($_GET, 'out_trade_no')]);
    }

    /**
     * 退款
     */
    public function refund(): OutputInterface
    {
        $service = aval($this->data, 'service');
        $trade_no = aval($this->data, 'trade_no');
        $reund_fee = aval($this->data, 'reund_fee');
        $notify_url = aval($this->data, 'notify_url');
        $batch_no = aval($this->data, 'batch_no');
        $batch_num = aval($this->data, 'batch_num', 1);
        $reason = aval($this->data, 'reason');
        $input_charset = aval($this->data, 'input_charset');
        $partner = aval($this->data, 'partner');
        $seller_email = aval($this->data, 'seller_email');
        if (!$service || !$trade_no || !$reund_fee || !$notify_url || !$partner) {
            return $this->output->withCode(21003);
        }
        $parameter = array(
            "service" => $service,
            "partner" => $partner,
            "seller_email" => $seller_email,
            "refund_date" => date('Y-m-d H:i:s', TIMESTAMP),
            "batch_no" => $batch_no,
            "batch_num" => $batch_num,
            "_input_charset" => $input_charset,
            'notify_url' => $notify_url,
            'detail_data' => $trade_no . '^' . $reund_fee . '^' . $reason
        );
        $reqUrl = lib\Api::getRequestURL($parameter);
        $data = ['url' => $reqUrl];
        return $this->output->withCode(200)->withData($data);
    }

    /**
     * 退款异步回调
     */
    public function refundNotify(): OutputInterface
    {
        //回调日志
        File::log('alipay/paylog/refundNotify')->info('退款异步回调:' . $_SERVER['REQUEST_URI'], $_POST);
        //计算得出通知验证结果
        $verify_result = lib\Notify::verifyNotify();
        if ($verify_result) {
            $refundNotifyDo = aval($this->data, 'refundNotifyDo');
            $refundNotifyDo && call_user_func($refundNotifyDo, $_POST);
            return $this->output->withCode(200, 100);
        }
        return $this->output->withCode(101);
    }
}
