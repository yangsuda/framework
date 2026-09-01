<?php
/**
 * 空实现钩子（默认通过）
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

/**
 * 钩子空实现
 *
 * 当某表没有对应的 app\Table\XxxTable 类、或该类未实现某钩子方法时，
 * 分发器返回此对象，避免业务层到处写 is_callable 判断。
 */
final class NullTableHook implements TableHookInterface
{
    use TableHookTrait;
}
