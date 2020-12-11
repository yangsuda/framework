<?php

declare(strict_types=1);

namespace SlimCMS\Error;

use Exception;
use SlimCMS\Core\Output;

class TextException extends Exception
{
    private $result;

    protected $loggerName;

    public function __construct($code, $param = [], $loggerName = 'slimCMS')
    {
        //应用实例引入还要传多一个参数，此处暂时不走容器，直接new了
        $output = new Output();
        if(!CORE_DEBUG){
            //防止debug关掉时，一些物理地址都打印出来
            unset($param['title']);
        }
        $this->result = $output->withCode($code, $param);
        $this->loggerName = $loggerName;
    }

    public function getResult()
    {
        return $this->result;
    }

    public function getLoggerName()
    {
        return $this->loggerName;
    }
}
