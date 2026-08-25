<?php
/**
 * 外部请求处理类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Core;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use SlimCMS\Interfaces\CookieInterface;

class Cookie implements CookieInterface
{
    private $setting;
    private ServerRequestInterface $request;

    public function __construct(ContainerInterface $container, ServerRequestInterface $request)
    {
        $this->setting = $container->get('settings');
        $this->request = $request;
    }

    public function set(string $key, $value = '', int $life = 0)
    {
        $value = (string)$value;
        $cookie = $this->setting['cookie'];
        $var = $cookie['cookiepre'] . $key;

        if ($value == '' || $life < 0) {
            $value = '';
            $life = -1;
        }

        $life = $life > 0 ? time() + $life : ($life < 0 ? time() - 31536000 : 0);
        $secure = strtolower($this->request->getUri()->getScheme()) === 'https';
        return setcookie($var, $value, $life, $cookie['cookiepath'], $cookie['cookiedomain'], $secure, true);
    }

    public function get(string $key)
    {
        $key = $this->setting['cookie']['cookiepre'] . $key;
        return aval($this->request->getCookieParams(), $key);
    }
}
