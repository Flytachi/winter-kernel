<?php

namespace Main;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Middleware\MiddlewareException;
use Flytachi\Winter\Kernel\Http\Stereotype\Middleware;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class AuthMiddleware extends Middleware
{
    public function before(HttpRequest $request, HttpResponse $response): void
    {
        // middleware logic
    }
}
