<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory\Middleware;

interface MiddlewareInterface
{
    public function optionBefore(): void;

    /**
     * @template Resource
     * @param Resource $resource
     * @return Resource
     */
    public function optionAfter(mixed $resource): mixed;
}
