<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route;

final class RouteResult
{
    public const NOT_FOUND          = 0;
    public const FOUND              = 1;
    public const METHOD_NOT_ALLOWED = 2;

    public function __construct(
        public readonly int $status,
        public readonly mixed $handler = null,
        public readonly array $params = [],
        public readonly array $allowedMethods = [],
    ) {
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
    public function isNotFound(): bool
    {
        return $this->status === self::NOT_FOUND;
    }
    public function isMethodNotAllowed(): bool
    {
        return $this->status === self::METHOD_NOT_ALLOWED;
    }
}
