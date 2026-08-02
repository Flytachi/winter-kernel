<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Exception;

trait ExceptionHeaderTrait
{
    private array $extraHeaders = [];

    public function withHeader(string $name, string $value): static
    {
        $this->extraHeaders[$name] = $value;
        return $this;
    }

    public function getExtraHeaders(): array
    {
        return $this->extraHeaders;
    }
}
