<?php

declare(strict_types=1);

namespace SlimCMS\Traits;

use SlimCMS\Helper\Captcha;
use SlimCMS\Interfaces\OutputInterface;

/**
 * 表单验证码校验 trait
 *
 * @deprecated 已被 SlimCMS\Core\Form\FormServiceBus::formVerify() 取代。
 *             新代码请直接注入 FormServiceBus，不要 use 本 trait。
 *             本 trait 保留仅为兼容性目的，将在下一大版本移除。
 */
trait Form
{
    public function formVerify( string $ccode = null): self
    {
        //如启用验证码，对验证码验证
        if (isset($ccode) && $this->session()->get('VerifyCode') != strtolower($ccode)) {
            $this->session()->delete('VerifyCode');
            $this->output = $this->output->withCode(24023);
        }
        return $this;
    }
}
