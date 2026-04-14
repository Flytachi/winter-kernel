<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Middleware;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;

/**
 * K2 Middleware contract.
 *
 * Implement in application middleware:
 *   class AuthMiddleware extends Middleware { ... }
 *
 * Apply via attribute on controller class or method:
 *   #[AuthMiddleware]
 *   class UserController extends Controller { ... }
 */
interface MiddlewareInterface
{
    /**
     * Runs before the controller method.
     * Throw MiddlewareException (or ResponseException) to abort the request.
     */
    public function before(HttpRequest $request, HttpResponse $response): void;

    /**
     * Runs after the controller method, in reverse registration order.
     * May transform or replace the return value.
     *
     * @template T
     * @param T $result
     * @return T
     */
    public function after(mixed $result): mixed;
}
