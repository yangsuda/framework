<?php

declare(strict_types=1);

namespace SlimCMS\Traits;

trait Table
{
    public function t(string $name = '', string $extendName = null): \SlimCMS\Core\Table
    {
        static $objs = [];
        $name = $name ?: 'forms';
        $className = ucfirst($name);
        $classname = '\app\Table\\' . $className . 'Table';
        if (!class_exists($classname)) {
            $classname = 'app\Core\Table';
        }
        if (empty($objs[$name . $extendName])) {
            $objs[$name . $extendName] = $this->i($classname)->setTableName($name, $extendName);
        }
        return $objs[$name . $extendName];
    }
}
