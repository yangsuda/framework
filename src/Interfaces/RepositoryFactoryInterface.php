<?php
/**
 * 仓储工厂接口
 *
 * 收拢"按表名/类名动态解析仓储"的机制：
 * 动态表单场景下仓储依赖在运行期才能确定，无法构造注入，
 * 通过窄接口工厂替代业务类直接访问容器（Service Locator）。
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Interfaces;

use SlimCMS\Abstracts\RepositoryAbstract;

interface RepositoryFactoryInterface
{
    /**
     * 按表名解析仓储，无专属 Repository 类时兜底通用仓库
     * @param string $table 表名（不含前缀）
     */
    public function forTable(string $table): RepositoryAbstract;
}
