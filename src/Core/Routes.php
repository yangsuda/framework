<?php
declare(strict_types=1);

namespace SlimCMS\Core;

use App\Core\RouteAction;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use SlimCMS\Interfaces\RouteInterface;
use App\Core\Request;
use App\Core\Response;

class Routes implements RouteInterface
{
    /**
     * {@inheritdoc}
     */
    public function __invoke(App $app)
    {
        return $this->route($app);
    }

    /**
     * {@inheritdoc}
     */
    public function route(App $app)
    {
        // 设置 RouteAction 门面的路由收集器（支持 Route::get() 等静态调用）
        RouteAction::setCollector($app);

        // 加载路由文件（先加载具体路由）
        $this->loadRouteFiles($app);

        //其它未匹配到路由兜底
        RouteAction::any('/{path:.*}', 'Main\MainController@notFound');
    }

    /**
     * 加载所有路由文件
     */
    protected function loadRouteFiles(App $app): void
    {
        $routeFiles = [
            CSAPP . 'routes/main.php',
            CSAPP . 'routes/admin.php',
        ];
        // 创建一个递归目录迭代器
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(CSAPP . 'routes/plugin'));
        // 遍历目录和子目录
        foreach ($iterator as $f) {
            $pathName = realpath($f->getPathname());
            if ($f->getExtension() === 'php') {
                $routeFiles[] = str_replace('\\', '/', $pathName);
            }
        }
        foreach ($routeFiles as $file) {
            if (file_exists($file)) {
                require $file;
            }
        }
    }
}
