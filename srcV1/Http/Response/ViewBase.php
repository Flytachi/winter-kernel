<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Kernel;

abstract class ViewBase implements ViewInterface
{
    protected array $headers = [];
    protected ?string $callClass;
    protected ?string $callClassMethod;
    protected ?string $templateName;
    protected string $resourceName;
    protected array $data;
    protected HttpCode $httpCode;

    public function __construct(
        ?string $templateName,
        string $resourceName,
        array $data = [],
        HttpCode $httpCode = HttpCode::OK
    ) {
        if (empty($templateName)) {
            $this->templateName = null;
        } else {
            $this->templateName = $templateName;
            if (!file_exists($this->getTemplate())) {
                throw new \Exception($this->getTemplate() . ' not found');
            }
        }
        $this->resourceName = $resourceName;
        if (!file_exists($this->getResource())) {
            throw new \Exception($this->getResource() . ' not found');
        }
        $this->data = $data;
        $this->httpCode = $httpCode;
        $backtrace = debug_backtrace();
        if (isset($backtrace[1]['class'])) {
            $index = $backtrace[1]['class'] == static::class ? 2 : 1;
            $backtrace = $backtrace[$index] ?? [];
        } else {
            $backtrace = [];
        }
        $this->callClass = $backtrace['class'] ?? null;
        $this->callClassMethod = $backtrace['function'] ?? null;
    }

    public function defaultHeaders(): array
    {
        return ['Content-Type' => 'text/html; charset=utf-8'];
    }

    final public function addHeader(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    final public function getHttpCode(): HttpCode
    {
        return $this->httpCode;
    }

    final public function getCallClass(): ?string
    {
        return $this->callClass;
    }

    final public function getCallClassMethod(): ?string
    {
        return $this->callClassMethod;
    }

    final public function getHeader(): array
    {
        return [...$this->defaultHeaders(), ...$this->headers];
    }

    final public function getTemplate(): ?string
    {
        if ($this->templateName == null) {
            return null;
        }
        return Kernel::$pathResource . '/' . $this->templateName . '.php';
    }

    final public function getResource(): string
    {
        return Kernel::$pathResource . '/' . $this->resourceName . '.php';
    }

    final public function getData(): array
    {
        return $this->data;
    }
}
