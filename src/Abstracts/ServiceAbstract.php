<?php
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use Slim\App;
use SlimCMS\Error\TextException;
use SlimCMS\Interfaces\OutputInterface;

abstract class ServiceAbstract extends BaseAbstract
{

    protected OutputInterface $output;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->output = $this->container->get(OutputInterface::class)($app);
    }
}
