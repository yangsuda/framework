<?php
/**
 * control继承抽象类
 */

declare(strict_types=1);

namespace SlimCMS\Abstracts;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use SlimCMS\Core\Request;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\Str;
use SlimCMS\Interfaces\OutputInterface;

abstract class ControlAbstract extends BaseAbstract
{
    /**
     * 路由地址
     * @var string
     */
    protected $p = '';

    public function __construct(App $app, ServerRequestInterface $request = null)
    {
        parent::__construct($app, $request);
        $this->p = $this->request->getUri()->getPath();
    }

    /**
     * 加载模板输出
     * @param array $result
     * @return array|\Psr\Http\Message\ResponseInterface
     */
    protected function view(OutputInterface $output = null, string $template = ''): MessageInterface
    {
        $output = $output ?? $this->output;
        $template = $template ?: $this->p;
        if (empty($template)) {
            return $this->response($output->withCode(21017));
        }
        $data = [];
        $data['formhash'] = Str::formhash($this->session());
        $data['csrfToken'] = $this->request->getAttribute('csrfToken');
        $data['errorCode'] = $this->getFlash('errorCode');
        $data['errorMsg'] = $this->getFlash('errorMsg');
        parse_str($this->request->getUri()->getQuery(), $querys);
        $data['query'] = http_build_query($querys);
        $data['p'] = $this->p;
        $output = $output->withTemplate((string)$template)->withData($data);
        $content = $output->analysisTemplate();
        $response = $this->response->withHeader('Content-type', 'text/html');
        $response->getBody()->write($content);
        return $response;
    }

    /**
     * 直接跳转
     * @param array $result
     * @return array|\Psr\Http\Message\ResponseInterface
     */
    protected function directTo(OutputInterface $output = null)
    {
        $this->setFlash('errorCode', $output->getCode());
        $this->setFlash('errorMsg', $output->getMsg());
        if (empty($output->getReferer())) {
            throw new TextException(21063);
        }
        $response = $this->response->withHeader('location', $output->getReferer());
        return $response;

    }

    /**
     * 设置Flash消息
     * @param $key
     * @param $message
     */
    private function setFlash($key, $message)
    {
        $this->session()->set('_flash' . $key, $message);
    }

    /**
     * 获取Flash消息
     * @param $key
     * @return mixed|null
     */
    private function getFlash($key)
    {
        $key = '_flash' . $key;
        $session = $this->session();
        if ($session->has($key)) {
            $message = $session->get($key);
            $session->delete($key); // 关键：读取后立即删除
            return $message;
        }
        return null;
    }

    /**
     * JSON输出
     * @param OutputInterface|null $output
     * @return MessageInterface
     */
    protected function json(OutputInterface $output = null): MessageInterface
    {
        $response = $this->response->withHeader('Content-type', 'application/json');
        $encodedOutput = json_encode($output, JSON_PRETTY_PRINT);
        $response->getBody()->write($encodedOutput);
        return $response;
    }

    /**
     * 自动判断输出方式
     * @param OutputInterface|null $output
     * @return MessageInterface
     */
    protected function response(OutputInterface $output = null): MessageInterface
    {
        $contentType = $this->determineContentType();
        $contentType = $contentType ?: 'application/json';
        if ($contentType == 'text/html') {
            return $this->view($output);
        }
        $response = $this->response->withHeader('Content-type', $contentType);
        $encodedOutput = json_encode($output, JSON_PRETTY_PRINT);
        $response->getBody()->write($encodedOutput);
        return $response;
    }

    /**
     * 自动获取请求头Accept的Content-Type
     * @return string|null
     */
    protected function determineContentType(): ?string
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

    protected function request(): Request
    {
        return $this->i(Request::class);
    }

    /**
     * 获取外部传入数据
     * @param $name
     * @param $type
     * @return array|mixed|\都不存在时的默认值|null
     */
    protected function input($name, $type = 'string')
    {
        return $this->request()->input($name, $type);
    }

    /**
     * 获取强转int类型外部传入数据
     * @param string $name
     * @return int
     */
    protected function inputInt(string $name): int
    {
        return (int)$this->request()->input($name, 'int');
    }

    /**
     * 获取强转float类型外部传入数据
     * @param string $name
     * @return float
     */
    protected function inputFloat(string $name): float
    {
        return (float)$this->request()->input($name, 'float');
    }

    /**
     * 获取强转string类型外部传入数据
     * @param string $name
     * @return string
     *
     */
    protected function inputString(string $name): string
    {
        return (string)$this->request()->input($name);
    }
}