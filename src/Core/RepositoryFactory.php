<?php
/**
 * 仓储工厂实现
 *
 * 机制收拢于此，业务类只依赖 RepositoryFactoryInterface。
 * 注：构造使用传统赋值写法，避免 PHP-DI 6.4 对 PHP8 构造器属性提升的 autowire 反射兼容问题。
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use SlimCMS\Abstracts\BaseAbstract;
use SlimCMS\Abstracts\GenericRepository;
use SlimCMS\Abstracts\RepositoryAbstract;
use SlimCMS\Interfaces\RepositoryFactoryInterface;

final class RepositoryFactory implements RepositoryFactoryInterface
{
    private ContainerInterface $container;
    private App $app;

    public function __construct(ContainerInterface $container, App $app)
    {
        $this->container = $container;
        $this->app = $app;
    }

    public function forTable(string $table): RepositoryAbstract
    {
        $className = '\app\Repository\\' . ucfirst($table) . 'Repository';
        if (class_exists($className)) {
            return $this->make($className);
        }
        return $this->make(GenericRepository::class, ['tableName' => $table]);
    }

    /**
     * 每次新建实例（仓储持有查询状态，不能复用），并回填请求实例
     */
    private function make(string $className, array $extra = []): RepositoryAbstract
    {
        $repo = $this->container->make($className, array_merge(['app' => $this->app], $extra));
        if ($repo instanceof BaseAbstract) {
            $repo->setRequest($this->container->get(ServerRequestInterface::class));
        }
        return $repo;
    }
}
