<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Base\Log\LoggerRegistry;
use Flytachi\Winter\Kernel\Http\Res\ResourceTree;
use Flytachi\Winter\Kernel\Http\Response\ExceptionWrapper;
use Flytachi\Winter\Kernel\Http\Response\ResponseFileContentInterface;
use Flytachi\Winter\Kernel\Http\Response\ResponseInterface;
use Flytachi\Winter\Kernel\Http\Response\ViewInterface;

final class Rendering
{
    private HttpCode $httpCode;
    private array $header = [];
    private null|int|float|string|array $body;
    private ?string $resource = null;
    private int $action = 0;

    public function setResource(mixed $resource): void
    {
        if ($resource instanceof ResponseInterface) {
            $this->httpCode = $resource->getHttpCode();
            $this->body = $resource->getBody();
            $this->header = $resource->getHeader();
        } elseif ($resource instanceof ResponseFileContentInterface) {
            $this->httpCode = $resource->getHttpCode();
            $this->body = $resource->getBody();
            $this->resource = $resource->getFileName();
            $this->action = 2;
            $this->header = $resource->getHeader();
        } elseif ($resource instanceof ViewInterface) {
            $this->httpCode = $resource->getHttpCode();
            $this->resource = $resource->getResource();
            $this->body = $resource->getData();
            $this->action = 1;
            $this->header = $resource->getHeader();
            ResourceTree::init(
                $resource->getCallClass(),
                $resource->getCallClassMethod(),
                $resource->getTemplate(),
                $resource->getResource()
            );
        } elseif ($resource instanceof \Throwable) {
            $this->httpCode = HttpCode::tryFrom((int) $resource->getCode()) ?: HttpCode::UNKNOWN_ERROR;
            $this->logging($resource);
            $this->body = ExceptionWrapper::wrapBody($resource);
            $this->header = ExceptionWrapper::wrapHeader();
        } else {
            $this->httpCode = empty($resource) ? HttpCode::NO_CONTENT : HttpCode::OK;
            $this->body = $resource;
        }
    }

    public function render(): never
    {
        header_remove("X-Powered-By");
        header("HTTP/1.1 {$this->httpCode->value} " . $this->httpCode->message());
        header("Status: {$this->httpCode->value} " . $this->httpCode->message());
        foreach ($this->header as $name => $value) {
            header("{$name}: {$value}");
        }

        // without content
        if ($this->httpCode->value == 204 || $this->httpCode->isRedirection()) {
            LoggerRegistry::instance('Rendering')->debug(sprintf(
                "HTTP [%d] %s",
                $this->httpCode->value,
                $this->httpCode->message(),
            ));
            exit;
        }

        if ($this->action === 1) {
            LoggerRegistry::instance('Rendering')->debug(sprintf(
                "HTTP [%d] %s -> %s",
                $this->httpCode->value,
                $this->httpCode->message(),
                $this->resource
            ));
            ResourceTree::render($this->body);
        } elseif ($this->action === 2) {
            LoggerRegistry::instance('Rendering')->debug(sprintf(
                "HTTP [%d] %s -> %s",
                $this->httpCode->value,
                $this->httpCode->message(),
                $this->resource
            ));
            file_put_contents('php://output', $this->body);
        } else {
            LoggerRegistry::instance('Rendering')->debug(sprintf(
                "HTTP [%d] %s -> %s",
                $this->httpCode->value,
                $this->httpCode->message(),
                $this->body ?: ''
            ));
            echo $this->body;
        }
        exit;
    }

    private function logging(\Throwable $resource): void
    {
        $logType = $resource instanceof Exception
            ? $resource->getLogLevel()
            : 'alert';
        if ((bool) env('DEBUG', false)) {
            LoggerRegistry::instance($resource::class)->{$logType}(sprintf(
                "%d: %s -> %s(%d)\n#Stack trace:\n%s",
                $resource->getCode(),
                $resource->getMessage(),
                $resource->getFile(),
                $resource->getLine(),
                $resource->getTraceAsString(),
            ));
        } else {
            LoggerRegistry::instance($resource::class)->{$logType}(sprintf(
                "%d: %s -> %s(%d)",
                $resource->getCode(),
                $resource->getMessage(),
                $resource->getFile(),
                $resource->getLine()
            ));
        }
    }
}
