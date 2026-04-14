<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Adapter;

use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Swoole\Http\Response;

/**
 * HttpResponse adapter for the Swoole runtime.
 * A thin proxy — Swoole's API matches the interface exactly.
 */
final class SwooleResponse implements HttpResponse
{
    public function __construct(private readonly Response $response) {}

    public function status(int $code): void
    {
        $this->response->status($code);
    }

    public function header(string $name, string $value): void
    {
        $this->response->header($name, $value);
    }

    public function end(string $body = ''): void
    {
        $this->response->end($body);
    }

    /** Access the underlying Swoole response (e.g. for sendfile). */
    public function getSwooleResponse(): Response
    {
        return $this->response;
    }
}
