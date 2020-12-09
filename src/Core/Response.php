<?php
/**
 * 数据输出处理类
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core;

use Psr\Http\Message\ResponseInterface;
use SlimCMS\Helper\Crypt;
use SlimCMS\Interfaces\OutputInterface;
use SlimCMS\Abstracts\MessageAbstract;

class Response extends MessageAbstract
{

    /**
     * 返回提示数据
     * @param $code
     * @return array
     */
    public function output(OutputInterface $output): ResponseInterface
    {
        if ($output->directTo) {
            return $this->directTo($output);
        }
        if ($output->jsonCallback) {
            return $this->jsonCallback($output);
        }
        if ($output->json) {
            $contentType = 'application/json';
        } else {
            $contentType = $this->determineContentType();
            $contentType = $contentType ?: 'application/json';
            if ($contentType == 'text/html') {
                return $this->view($output);
            }
        }
        $this->response = $this->response->withHeader('Content-type', $contentType);
        $encodedOutput = json_encode($output, JSON_PRETTY_PRINT);
        $this->response->getBody()->write($encodedOutput);
        return $this->response;
    }

    /**
     * 模板渲染
     * @param OutputInterface $output
     * @return ResponseInterface
     */
    public function view(OutputInterface $output): ResponseInterface
    {
        $content = $output->analysisTemplate();
        $this->response = $this->response->withHeader('Content-type', 'text/html');
        $this->response->getBody()->write($content);
        return $this->response;
    }

    /**
     * 获取文本内容类型
     * @return string
     */
    private function determineContentType(): ?string
    {
        $accept = ['application/json', 'application/xml', 'text/xml', 'text/html', 'text/plain'];
        $acceptHeader = $this->request->getHeaderLine('Accept');
        $selectedContentTypes = array_intersect(
            explode(',', $acceptHeader),
            $accept
        );
        $count = count($selectedContentTypes);

        if ($count) {
            $current = current($selectedContentTypes);

            //当通过Accept头提供多个内容类型时,确保其他受支持的内容类型优先于text/plain
            if ($current === 'text/plain' && $count > 1) {
                return next($selectedContentTypes);
            }
            return $current;
        }

        if (preg_match('/\+(json|xml)/', $acceptHeader, $matches)) {
            $mediaType = 'application/' . $matches[1];
            if (array_key_exists($mediaType, $accept)) {
                return $mediaType;
            }
        }
        return null;
    }

    /**
     * JSONP数据返回
     * @param Output $result
     * @return ResponseInterface
     */
    protected function jsonCallback(Output $output)
    {
        $encodedOutput = $output->getJsonCallback() . '(' . json_encode($output) . ')';
        $this->response = $this->response->getBody()->write($encodedOutput);
        return $this->response;
    }

    /**
     * 直接跳转
     * @param Output $result
     * @return ResponseInterface
     */
    protected function directTo(Output $output)
    {
        self::$cookie->set('errorCode', Crypt::encrypt((string)$output->getCode()));
        self::$cookie->set('errorMsg', Crypt::encrypt((string)$output->getMsg()));
        $this->response = $this->response->withHeader('location', $output->getReferer());
        return $this->response;
    }
}