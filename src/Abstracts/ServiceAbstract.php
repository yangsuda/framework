<?php
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use SlimCMS\Error\TextException;

abstract class ServiceAbstract extends BaseAbstract
{

    /**
     * 获取仓库类名
     * @param string $name
     * @return string
     */
    protected function getRepositoryClassName(string $name):string
    {
        return '\app\Repository\\' . ucfirst($name) . 'Repository';
    }
}
