<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response;

trait ResponseTrait
{
    protected array $headers = [];

    final public function addHeader(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    final public function getHeader(): array
    {
        return $this->headers;
    }
}
