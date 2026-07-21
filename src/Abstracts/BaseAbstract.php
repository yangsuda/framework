<?php
/**
 * control、model共同继承抽象类
 */

declare(strict_types=1);

namespace SlimCMS\Abstracts;

use App\Core\Redis;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;
use APP\Core\Request;
use APP\Core\Response;
use APP\Core\Table;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\Crypt;
use SlimCMS\Helper\Str;
use SlimCMS\Interfaces\OutputInterface;

abstract class BaseAbstract
{
    /**
     * 请求对象实例
     * @var Request
     */
    protected static $request;

    /**
     * 响应对象实例
     * @var Response
     */
    protected static $response;

    protected static $output;

    protected static $container;

    /**
     * redis实例
     * @var \Redis|null
     *
     */
    protected static $redis;

    /**
     * 后台配置参数
     * @var
     */
    protected static $config;

    /**
     * 站点初始化参数
     * @var
     */
    protected static $setting;

    public function __construct(Request $request, Response $response)
    {
        self::$request = $request;
        self::$response = $response;
        self::$output = self::$request->getOutput();
        self::$container = self::$request->getContainer();
        self::$redis = self::$container->get(Redis::class);
        self::$config = self::$container->get('cfg');
        self::$setting = self::$container->get('settings');
    }

    public static function t(string $name = '', string $extendName = null): Table
    {
        static $objs = [];
        $name = $name ?: 'forms';
        $className = ucfirst($name);
        $classname = '\App\Table\\' . $className . 'Table';
        if (!class_exists($classname)) {
            $classname = 'App\Core\Table';
        }
        if (empty($objs[$name . $extendName])) {
            $objs[$name . $extendName] = new $classname(self::$request, $name, $extendName);
        }
        return $objs[$name . $extendName];
    }

    /**
     * 获取外部传入数据
     * @param $name
     * @param $type
     * @return array|mixed|\都不存在时的默认值|null
     */
    protected static function input($name, $type = 'string')
    {
        return self::$request->input($name, $type);
    }

    /**
     * 获取强转int类型外部传入数据
     * @param string $name
     * @return int
     */
    protected static function inputInt(string $name): int
    {
        return (int)self::$request->input($name, 'int');
    }

    /**
     * 获取强转float类型外部传入数据
     * @param string $name
     * @return float
     */
    protected static function inputFloat(string $name): float
    {
        return (float)self::$request->input($name, 'float');
    }

    /**
     * 获取强转string类型外部传入数据
     * @param string $name
     * @return string
     *
     */
    protected static function inputString(string $name): string
    {
        return (string)self::$request->input($name);
    }

    /**
     * 数据格式输出
     * @param $result
     * @return array|\Psr\Http\Message\ResponseInterface
     */
    protected static function response(OutputInterface $output = null)
    {
        $output = $output ?? self::$output;
        return self::$response->output($output);
    }

    /**
     * 生成缓存KEY
     * @param $key
     * @param mixed ...$param
     * @return string
     */
    protected static function cacheKey($key, ...$param): string
    {
        return get_called_class() . ':' . $key . ':' . Str::md5key($param);
    }

    /**
     * URL处理
     * @param string $url
     * @param string $host
     * @return string
     */
    public static function url(string $url = '', string $path = ''): string
    {
        $uri = self::$request->getRequest()->getUri();
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
        return $path. ($query ? '?' . $query : '');
    }
}
