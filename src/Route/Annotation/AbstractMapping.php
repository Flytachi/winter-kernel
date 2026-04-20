<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route\Annotation;

abstract class AbstractMapping
{
    public function __construct(public readonly string $url = '')
    {
    }

    public function getMethod(): ?string
    {
        return null;
    }

    public function getUrl(): string
    {
        return trim($this->url, '/');
    }
}
