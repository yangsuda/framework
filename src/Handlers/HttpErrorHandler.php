<?php
declare(strict_types=1);

namespace SlimCMS\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Error\Renderers\HtmlErrorRenderer;
use Slim\Error\Renderers\JsonErrorRenderer;
use Slim\Error\Renderers\PlainTextErrorRenderer;
use Slim\Error\Renderers\XmlErrorRenderer;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Handlers\ErrorHandler;
use SlimCMS\Core\Error;
use SlimCMS\Error\JsonError;
use SlimCMS\Error\PlainTextError;
use SlimCMS\Error\HtmlError;
use SlimCMS\Error\XmlError;
use SlimCMS\Error\TextException;

class HttpErrorHandler extends ErrorHandler
{
    /**
     * {@inheritdoc}
     */
    protected $defaultErrorRenderer = HtmlError::class;

    /**
     * {@inheritdoc}
     */
    protected $logErrorRenderer = PlainTextError::class;

    /**
     * {@inheritdoc}
     */
    protected array $errorRenderers = [
        'application/json' => JsonErrorRenderer::class,
        'application/xml' => XmlErrorRenderer::class,
        'text/xml' => XmlErrorRenderer::class,
        'text/html' => HtmlErrorRenderer::class,
        'text/plain' => PlainTextErrorRenderer::class,
    ];

    /**
     * @inheritdoc
     */
    protected function respond(): Response
    {

        $exception = $this->exception;
        $func = function ($encodedOutput) {
            $response = $this->responseFactory->createResponse();
            if ($this->contentType !== null && array_key_exists($this->contentType, $this->errorRenderers)) {
                $response = $response->withHeader('Content-type', $this->contentType);
            } else {
                $response = $response->withHeader('Content-type', $this->defaultErrorRendererContentType);
            }
            $response->getBody()->write($encodedOutput);
            return $response;
        };
        if ($exception instanceof TextException) {
            $encodedOutput = json_encode($exception->getResult(), JSON_PRETTY_PRINT);
            return $func($encodedOutput);
        }
        if (CORE_DEBUG === true) {
            return parent::respond();
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function logError(string $error): void
    {
        if ($this->exception instanceof TextException) {
            $this->logger = $this->logger->withName($this->exception->getLoggerName());
            $error = $this->exception->getResult()->getMsg() . ' ' . $error;
            $this->logger->alert($error);
        } else {
            $this->logger->error($error);
        }
    }
}
