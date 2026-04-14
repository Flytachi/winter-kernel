<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Contracts;

/**
 * Unified HTTP response abstraction.
 *
 * Implemented by:
 *   - SwooleResponse — wraps Swoole\Http\Response
 *   - FpmResponse    — uses header() / http_response_code() / echo
 *
 * API deliberately mirrors Swoole's Response so SwooleResponse is a thin proxy.
 */
interface HttpResponse
{
    /** Set the HTTP status code. */
    public function status(int $code): void;

    /** Add or replace a response header. */
    public function header(string $name, string $value): void;

    /** Flush the body and finish the response. */
    public function end(string $body = ''): void;
}
