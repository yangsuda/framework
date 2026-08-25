<?php

declare(strict_types=1);

namespace SlimCMS\Traits;

use SlimCMS\Helper\Captcha;
use SlimCMS\Interfaces\OutputInterface;

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
