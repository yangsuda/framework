<?php
/**
 * control继承抽象类
 */

declare(strict_types=1);

namespace SlimCMS\Abstracts;

use SlimCMS\Helper\Crypt;
use SlimCMS\Interfaces\OutputInterface;

abstract class ControlAbstract extends BaseAbstract
{
    /**
     * 加载模板输出
     * @param array $result
     * @return array|\Psr\Http\Message\ResponseInterface
     */
    public function view(OutputInterface $output = null, string $template = '')
    {
        $p = self::input('p');
        $output = $output ?? self::$output;
        $template = $template ?: $p;
        if (empty($template)) {
            return self::response($output->withCode(21017));
        }
        $data = [];
        $data['formhash'] = self::$request->getFormHash();
        $errorCode = (string)self::$request->cookie()->get('errorCode');
        if ($errorCode) {
            $data['errorCode'] = Crypt::decrypt($errorCode);
            $errorMsg = (string)self::$request->cookie()->get('errorMsg');
            $data['errorMsg'] = Crypt::decrypt($errorMsg);
        }
        $data['currentUrl'] = self::url();
        $data['p'] = $p;
        $output = $output->withTemplate((string)$template)->withData($data);

        //删除操作时临时生成的cookie提示信息
        if ($errorCode) {
            self::$request->getCookie()->set('errorCode');
            self::$request->getCookie()->set('errorMsg');
        }
        return self::$response->view($output);
    }

    /**
     * 直接跳转
     * @param array $result
     * @return array|\Psr\Http\Message\ResponseInterface
     */
    public function directTo(OutputInterface $output = null)
    {
        $output = $output ?? self::$output;
        $output->directTo = 1;
        return self::response($output);
    }

    public function json(OutputInterface $output = null)
    {
        $output = $output ?? self::$output;
        $output->json = 1;
        return self::response($output);
    }

    /**
     * 跨域请求返回数据
     * @param array $result
     * @return array|\Psr\Http\Message\ResponseInterface
     *
     */
    public function jsonCallback(OutputInterface $output = null, string $jsonCallback)
    {
        $output = $output ?? self::$output;
        $output->jsonCallback = $jsonCallback;
        return self::response($output);
    }
}