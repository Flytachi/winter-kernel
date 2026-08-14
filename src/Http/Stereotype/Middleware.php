<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Stereotype;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Middleware\MiddlewareInterface;

/**
 * Base middleware — extend and override before() / after() as needed.
 *
 * Apply to controller class or method via attribute:
 * ```
 *   #[AuthMiddleware]
 *   class UserController extends Controller { ... }
 * ```
 *
 * @link https://winterframe.net/docs/middleware before / after, ordering and short-circuiting
 */
abstract class Middleware implements MiddlewareInterface
{
    public function before(HttpRequest $request, HttpResponse $response): void
    {
    }

    public function after(mixed $result): mixed
    {
        return $result;
    }
}
