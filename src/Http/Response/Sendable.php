<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response;

use Flytachi\Winter\K2\Http\Contracts\HttpResponse;

/**
 * Common contract for all K2 response objects.
 *
 * Any value returned from a controller method that implements this interface
 * will be serialized to the HttpResponse by the Router automatically.
 *
 * Implementations: ResponseEntity, ResponseFile, ResponseView
 */
interface Sendable
{
    public function send(HttpResponse $response): void;
}
