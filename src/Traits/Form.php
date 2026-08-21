<?php

declare(strict_types=1);

namespace SlimCMS\Traits;

use SlimCMS\Helper\ImageCode;
use SlimCMS\Interfaces\OutputInterface;

trait Form
{
    public function formVerify(string $formhash, string $ccode = null): self
    {
        $output = $this->container->get(OutputInterface::class)($this->app);
        $config = $this->container->get('cfg');
        $server = $this->request->getServerParams();
        $referer = '';
        if (!empty($server['HTTP_REFERER'])) {
            $parse = parse_url(aval($server, 'HTTP_REFERER'));
            $referer = $parse['host'];
        }
        $parse = parse_url($config['basehost']);
        $host = $parse['host'];

        if ($server['REQUEST_METHOD'] == 'POST' &&
            $formhash == $this->session()->get('formHash') &&
            empty($server['HTTP_X_FLASH_VERSION']) &&
            $host == $referer) {
            $this->session()->delete('formHash');
            $this->output = $this->output->withCode(200);
        } else {
            $this->output = $this->output->withCode(24024);
        }
        //如启用验证码，对验证码验证
        if (isset($ccode)) {
            if (ImageCode::checkCode($this->session(), $ccode) === false) {
                $this->output = $this->output->withCode(24023);
            }
        }
        return $this;
    }
}
