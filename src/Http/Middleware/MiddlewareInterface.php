<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Middleware;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;

/**
 * Middleware contract.
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
