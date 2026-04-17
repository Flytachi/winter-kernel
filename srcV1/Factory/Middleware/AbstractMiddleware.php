<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory\Middleware;

use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
abstract class AbstractMiddleware implements MiddlewareInterface
{
    final public function __construct()
    {
    }
    abstract public function optionBefore(): void;
    public function optionAfter(mixed $resource): mixed
    {
        return $resource;
    }
}
