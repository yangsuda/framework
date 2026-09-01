<?php
/**
 * 表钩子分发器
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use SlimCMS\Core\Form\DTO\FormDTO;

/**
 * 钩子分发器
 *
 * 替换原 Forms 中 $this->t($form['table'])->dataXxxBefore() 的字符串拼装调用。
 * 通过容器解析 app\Table\XxxTable，未注册时返回 NullTableHook。
 *
 * 缓存：同一进程内对同一 tableName 只解析一次。
 */
final class TableHookDispatcher
{
    /** @var array<string,TableHookInterface> */
    private array $cache = [];
    /** @var ServerRequestInterface|null 中间件处理后的 request（含 adminContext 等 attributes） */
    private ?ServerRequestInterface $request = null;

    public function __construct(
        private ContainerInterface $container,
    ) {}

    /**
     * 同步中间件处理后的 request
     *
     * 容器中的 ServerRequestInterface 是原始请求，不含中间件 attributes（如 adminContext）。
     * 控制器通过 RouteAction::setRequest 持有最新 request，需传播到 hook 实例。
     */
    public function setRequest(ServerRequestInterface $request): self
    {
        $this->request = $request;
        // 同步给已缓存的实例
        foreach ($this->cache as $instance) {
            if (method_exists($instance, 'setRequest')) {
                $instance->setRequest($request);
            }
        }
        return $this;
    }

    public function for(string $tableName): TableHookInterface
    {
        $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        if (isset($this->cache[$tableName])) {
            return $this->cache[$tableName];
        }

        $class = '\\app\\Table\\' . ucfirst($tableName) . 'Table';
        if (!class_exists($class)) {
            return $this->cache[$tableName] = new NullTableHook();
        }

        try {
            $instance = $this->container->get($class);
            if ($instance instanceof TableHookInterface) {
                // 同步中间件处理后的 request（含 adminContext 等 attributes）
                if ($this->request && method_exists($instance, 'setRequest')) {
                    $instance->setRequest($this->request);
                }
                return $this->cache[$tableName] = $instance;
            }
        } catch (\Throwable $e) {
            // 容器解析失败降级为空钩子，避免业务中断
            if ($this->container->has(LoggerInterface::class)) {
                $this->container->get(LoggerInterface::class)
                    ->warning('TableHookDispatcher 解析失败，降级 NullTableHook', [
                        'table' => $tableName,
                        'class' => $class,
                        'error' => $e->getMessage(),
                    ]);
            }
        }
        return $this->cache[$tableName] = new NullTableHook();
    }

    /**
     * 便捷方法：按 FormDTO 直接取钩子
     */
    public function forForm(FormDTO $form): TableHookInterface
    {
        return $this->for($form->table);
    }
}
