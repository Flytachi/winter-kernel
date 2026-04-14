<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Stereotype;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\Middleware\MiddlewareInterface;

/**
 * Base middleware — extend and override before() / after() as needed.
 *
 * Apply to controller class or method via attribute:
 *   #[AuthMiddleware]
 *   class UserController extends Controller { ... }
 */
abstract class Middleware implements MiddlewareInterface
{
    public function before(HttpRequest $request, HttpResponse $response): void {}

    public function after(mixed $result): mixed
    {
        return $result;
    }
}
