<?php
/**
 * 服务基类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use App\Core\Forms;
use App\Core\Request;
use App\Core\Response;

abstract class ServiceAbstract extends BaseAbstract
{
    protected static $instances;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->initialize();
    }

    protected function initialize()
    {

    }

    public static function instance()
    {
        $instance_name = static::class;
        if (empty(self::$instances[$instance_name])) {
            self::$instances[$instance_name] = new static(self::$request, self::$response);
        }
        return self::$instances[$instance_name];
    }
}
