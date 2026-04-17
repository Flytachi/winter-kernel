<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
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
