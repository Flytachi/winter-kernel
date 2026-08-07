<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;

/**
 * Common contract for all kernel response objects.
 *
 * Any value returned from a controller method that implements this interface
 * will be serialized to the HttpResponse by the Router automatically.
 *
 * The request is passed so responses can negotiate with it (HTTP Range,
 * conditional GET, content negotiation). Implementations that do not need it
 * may simply ignore the argument.
 *
 * Implementations: ResponseEntity, ResponseFile, ResponseStreamFile, ResponseView
 */
interface Sendable
{
    public function send(HttpResponse $response, HttpRequest $request): void;
}
