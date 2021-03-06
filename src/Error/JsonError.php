<?php

/**
 * Slim Framework (https://slimframework.com)
 *
 * @license https://github.com/slimphp/Slim/blob/4.x/LICENSE.md (MIT License)
 */

declare(strict_types=1);

namespace SlimCMS\Error;

use Slim\Error\AbstractErrorRenderer;
use Throwable;

use function get_class;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

/**
 * Default Slim application JSON Error Renderer
 */
class JsonError extends AbstractErrorRenderer
{
    /**
     * {@inheritdoc}
     */
    protected $defaultErrorTitle = 'SlimCMS Application Error';

    /**
     * {@inheritdoc}
     */
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        $error = ['message' => $this->getErrorTitle($exception)];

        if ($displayErrorDetails) {
            $error['exception'] = [];
            do {
                $error['exception'][] = $this->formatExceptionFragment($exception);
            } while ($exception = $exception->getPrevious());
        }

        return (string) json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param Throwable $exception
     * @return array
     */
    private function formatExceptionFragment(Throwable $exception): array
    {
        return [
            'type' => get_class($exception),
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }
}
