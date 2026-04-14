<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Middleware;

use Swoole\Http\Request;
use Swoole\Http\Response;

interface MiddlewareInterface
{
    /**
     * Runs before the controller method.
     * Throw MiddlewareException (or any ResponseException) to abort the request.
     */
    public function before(Request $request, Response $response): void;

    /**
     * Runs after the controller method.
     * May transform or replace the return value.
     *
     * @template T
     * @param T $result
     * @return T
     */
    public function after(mixed $result): mixed;
}
