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
            $err = json_decode($encodedOutput, true);
            $response = $this->responseFactory->createResponse();
            if ($this->contentType !== null && array_key_exists($this->contentType, $this->errorRenderers)) {
                $response = $response->withHeader('Content-type', $this->contentType);
                if ($this->contentType == 'text/html') {
                    $encodedOutput = <<<EOT
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统提示 · 出错了</title>
    <style>
       *{margin:0;padding:0;box-sizing:border-box}
        body{background:#f8fafc;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',sans-serif;padding:1.5rem;margin:0}
        .error-box{max-width:440px;width:100%;background:#fff;border-radius:28px;padding:2.8rem 2.2rem 2.2rem;box-shadow:0 8px 30px rgba(0,0,0,0.05);text-align:center;border:1px solid rgba(226,232,240,0.6)}
        .error-icon{font-size:3.2rem;display:block;margin-bottom:.3rem;line-height:1}
        .error-title{font-size:1.7rem;font-weight:600;color:#0f172a;margin:.2rem 0 .4rem;letter-spacing:-.01em}
        .error-desc{font-size:1rem;color:#475569;margin:.3rem 0 1.8rem;line-height:1.5;padding:0 .2rem}
        .error-detail{background:#fef2f2;border-radius:12px;padding:.8rem 1rem;margin:.2rem 0 1.6rem;font-size:.9rem;color:#1e293b;border-left:4px solid #dc2626;text-align:left;word-break:break-word;font-family:Menlo,Monaco,'Courier New',monospace}
        .error-detail span{display:block;font-weight:500;color:#b91c1c;font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.15rem}
        .actions{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:.7rem;margin-top:.2rem}
        .btn{display:inline-flex;align-items:center;justify-content:center;padding:.6rem 1.8rem;border-radius:60px;font-weight:500;font-size:.95rem;text-decoration:none;color:#1e293b;border:1px solid #e2e8f0;cursor:pointer;font-family:inherit;background:#fff;min-width:100px;transition:all .15s ease}
        .btn-primary{background:#0f172a;border-color:#0f172a;color:#fff;box-shadow:0 6px 14px rgba(15,23,42,0.10)}
        .btn-primary:hover{background:#1e293b;border-color:#1e293b;transform:scale(1.02)}
        .btn-secondary{background:#f8fafc;border-color:#e2e8f0}
        .btn-secondary:hover{background:#f1f5f9;border-color:#cbd5e1}
        .btn:active{transform:scale(.96)}
        @media(max-width:480px){.error-box{padding:2rem 1.5rem;border-radius:24px}.error-icon{font-size:2.6rem}.error-title{font-size:1.4rem}.error-detail{font-size:.8rem;padding:.6rem .8rem}.actions{flex-direction:column;width:100%}.btn{width:100%;padding:.7rem}}
    </style>
</head>
<body>
    <div class="error-box" role="alert" aria-labelledby="err-title">
        <span class="error-icon">⚠️</span>
        <h2 id="err-title" class="error-title">操作失败</h2>
        <p class="error-desc">系统遇到一个错误，请稍后重试或联系支持。</p>
        <div class="error-detail"><span>错误详情</span><code id="error-message">{$err['msg']}</code>
        </div>
        <div class="actions">
            <button class="btn btn-primary" onclick="window.location.reload()">⟳ 重试</button>
            <button class="btn btn-secondary" onclick="history.back()">← 返回</button>
        </div>
    </div>
</body>
</html>
EOT;;
                }
                $response->getBody()->write($encodedOutput);
            } else {
                $response = $response->withHeader('Content-type', $this->defaultErrorRendererContentType);
                $response->getBody()->write(json_encode($err, JSON_UNESCAPED_UNICODE));
            }

            return $response;
        };
        if ($exception instanceof TextException) {
            $encodedOutput = json_encode($exception->getResult(), JSON_UNESCAPED_UNICODE);
            return $func($encodedOutput);
        }
        if (CORE_DEBUG === true) {
            return parent::respond();
        }
        //生产环境下，所有异常统一返回 JSON 格式
        $statusCode = $this->exception->getCode() ?: 500;
        $errorPayload = [
            'code' => $statusCode,
            'data' => null,
            'message' => $this->exception->getMessage(),
        ];
        $response = $this->responseFactory->createResponse($statusCode);
        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($errorPayload, JSON_UNESCAPED_UNICODE));
        return $response;
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
