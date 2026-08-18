<?php
/**
 * control、model共同继承抽象类
 */

declare(strict_types=1);

namespace SlimCMS\Abstracts;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use SlimCMS\Core\Session;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\Str;
use SlimCMS\Core\Redis;
use SlimCMS\Interfaces\OutputInterface;

abstract class BaseAbstract
{
    protected App $app;
    /**
     * 请求实例
     * @var ServerRequestInterface
     */
    protected ServerRequestInterface $request;

    /**
     * 响应实例
     * @var ResponseInterface
     */
    protected ResponseInterface $response;

    protected OutputInterface $output;

    protected ContainerInterface $container;

    /**
     * redis实例
     * @var \Redis|null
     *
     */
    protected Redis $redis;

    /**
     * 后台配置参数
     * @var
     */
    protected array $config;

    /**
     * 站点初始化参数
     * @var
     */
    protected array $setting;

    public function __construct(App $app, ServerRequestInterface $request = null)
    {
        $this->app = $app;
        $this->container = $app->getContainer();
        $this->request = $request ?: $this->container->get(ServerRequestInterface::class);
        $this->response = $this->container->get(ResponseInterface::class);
        $this->output = $this->container->get(OutputInterface::class)($app);
        $this->redis = $this->container->get(Redis::class);
        $this->config = $this->container->get('cfg');
        $this->setting = $this->container->get('settings');
    }

    /**
     * 生成缓存KEY
     * @param $key
     * @param mixed ...$param
     * @return string
     */
    protected function cacheKey($key, ...$param): string
    {
        return get_called_class() . ':' . $key . ':' . Str::md5key($param);
    }

    public function i($class)
    {
        return $this->container->make($class, ['request' => $this->request, 'app' => $this->app]);
    }

    /**
     * @template T of RepositoryAbstract
     * @param class-string<T> $className
     * @return T|null
     */
    public function r(string $className): ?RepositoryAbstract
    {
        if (!class_exists($className)) {
            throw new TextException(503, "Repository class not found");
        }
        return $this->i($className);
    }

    /**
     * URL处理
     * @param string $url
     * @param string $host
     * @return string
     */
    protected function url(string $url = '', string $path = ''): string
    {
        $uri = $this->request->getUri();
        if (empty($url) || preg_match('/^&/', $url)) {
            $query = $uri->getQuery() . $url;
        } elseif (strpos($url, '?') !== false) {
            list($path, $query) = explode('?', $url);
        }
        !empty($query) && parse_str($query, $output);
        $query = !empty($output) ? http_build_query($output) : '';
        if (empty($path)) {
            $path = $uri->getPath();
        }
        return $path . ($query ? '?' . $query : '');
    }

    /**
     * 获取session实例
     * @return mixed
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function session(): Session
    {
        return $this->container->get(Session::class);
    }
}
