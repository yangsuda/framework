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
use SlimCMS\Helper\Str;
use SlimCMS\Interfaces\OutputInterface;
use SlimCMS\Interfaces\RepositoryFactoryInterface;

abstract class BaseAbstract
{
    protected App $app;
    /**
     * 请求实例
     * @var ServerRequestInterface
     */
    protected ServerRequestInterface $request;

    protected ContainerInterface $container;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->container = $app->getContainer();
        $this->request = $this->container->get(ServerRequestInterface::class);
    }

    /**
     * 设置请求实例，用于将中间件中数据传进来
     * @param ServerRequestInterface $request
     * @return $this
     */
    public function setRequest(ServerRequestInterface $request): self
    {
        $this->request = $request;
        return $this;
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

    public function i($class, array $extra = [])
    {
        return $this->container->make($class, array_merge(['app' => $this->app], $extra))->setRequest($this->request);
    }

    /**
     * @template T of RepositoryAbstract
     * @param class-string<T> $className
     * @return T|null
     */
    public function r(string $table): ?RepositoryAbstract
    {
        // 兼容层：解析机制已收拢到 RepositoryFactory，存量调用点可渐进迁移
        return $this->container->get(RepositoryFactoryInterface::class)->forTable($table);
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
