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
        if (empty($objs[$name.$extendName])) {
            $objs[$name.$extendName] = new $classname(self::$request, $name, $extendName);
        }
        return $objs[$name.$extendName];
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
    public static function url(string $url = '', string $host = ''): string
    {
        static $urls = [];
        $key = md5($url . $host);
        if (!empty($urls[$key])) {
            return $urls[$key];
        }
        $host = $host ?: self::$config['basehost'];
        $uri = self::$request->getRequest()->getUri();
        if (empty($url) || preg_match('/^&/', $url)) {
            $url = $uri->getQuery() . $url;
        }
        if (strpos($url, '?') !== false) {
            list($path, $url) = explode('?', $url);
        }
        if (!empty(self::$config['urlEncrypt']) && !empty($path) && preg_match('/\.html$/', $path)) {
            $data = Str::QAnalysis(urldecode(preg_replace('/^q-/', '', pathinfo($path, PATHINFO_FILENAME))));
            $data['p'] = parse_url(pathinfo($path, PATHINFO_DIRNAME), PHP_URL_PATH);
            $url = http_build_query($data) . '&' . $url;
        }

        parse_str($url, $output);
        if (!empty($output['q'])) {
            $q = preg_replace('/^q-/', '', $output['q']);
            $data = Str::QAnalysis($q);
            unset($output['q']);
            $output = array_merge($data, $output);
            $url = http_build_query($output);
        }

        parse_str($url, $output);
        foreach ($output as $k => $v) {
            if ($v === '') {
                unset($output[$k]);
            }
        }
        if (!empty(self::$config['urlEncrypt'])) {
            $p = $output['p'];
            unset($output['p']);
            if (!empty($output['q'])) {
                $data = Str::QAnalysis($output['q']);
                !empty($data['q']) && $data = Crypt::decrypt($data['q']);
                unset($output['q']);
                $output = array_merge($data, $output);
            }
            $url = 'p=' . $p . '&q=' . Crypt::encrypt($output);
        } else {
            $url = http_build_query($output);
        }

        if (empty(self::$config['rewriteUrl'])) {
            if (empty($path)) {
                $path = ltrim($uri->getPath(), '/');
                if (preg_match('/\.html$/', $path)) {
                    $server = self::$request->getRequest()->getServerParams();
                    $path = basename($server['SCRIPT_FILENAME']);
                }
            }
            $url = (preg_match('/^http/', $path) ? $path : rtrim($host, '/') . '/' . $path) . '?' . $url;
            return str_replace('%27', '\'', $url);
        }

        if (empty($path)) {
            $server = self::$request->getRequest()->getServerParams();
            $fileName = pathinfo($server['SCRIPT_FILENAME'], PATHINFO_FILENAME);
            $path = ltrim(dirname($uri->getPath()), '/');
            $path = str_replace(self::$config['basehost'], '', $path);
        } else {
            $path = str_replace(self::$config['basehost'], '', $path);
            $fileName = pathinfo($path, PATHINFO_FILENAME);
        }
        parse_str($url, $output);
        $data = Str::QAnalysis(pathinfo($path, PATHINFO_FILENAME));
        $data['p'] = str_replace($fileName, '', dirname($path));
        $output = array_merge($data, $output);

        if (empty(self::$config['urlEncrypt']) && !empty($output['q'])) {
            $data = Str::QAnalysis($output['q']);
            $data && $output = array_merge($data, $output);
            unset($output['q']);
        }
        foreach ($output as $k => $v) {
            if ($v === '') {
                unset($output[$k]);
            }
        }
        $url = rtrim($host, '/') . '/' . (!empty($output['p']) ? trim($output['p'], '/') . '/' : '');
        $jsoncallback = !empty($output['jsoncallback']);
        unset($output['p'], $output['jsoncallback']);
        if (!empty($output)) {
            $arr = [];
            foreach ($output as $k => $v) {
                $v = is_array($v) ? implode('`', $v) : $v;
                if (!empty($v) || $v == '0') {
                    $arr[] = urlencode(str_replace(['-', '_'], ['&#045;', '&#095;'], $k) . '-' . str_replace(['-', '_'], ['&#045;', '&#095;'], $v));
                }
            }
            if ($arr) {
                $val = implode('_', $arr);
                $url .= urlencode($val) . '.html';
                //方便JS中url的拼接生成URL
                $url = str_replace(['%2527%2B', '%2B%2527'], ['\'+', '+\''], $url);
            }
        }
        $urls[$key] = $url . ($jsoncallback ? '?jsoncallback=?' : '');
        return $urls[$key];
    }
}
