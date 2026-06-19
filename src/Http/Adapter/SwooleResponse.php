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
    /**
     * @param Response $response underlying Swoole response
     * @param bool $headOnly suppress the body (HEAD request) while keeping headers
     */
    public function __construct(
        private readonly Response $response,
        private readonly bool $headOnly = false,
    ) {
    }

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
        if ($this->headOnly && $body !== '') {
            // HEAD: keep the Content-Length GET would report, drop the body.
            $this->response->header('Content-Length', (string) strlen($body));
            $body = '';
        }

        $this->response->end($body);
    }

    public function sendfile(string $path, int $offset = 0, int $length = 0): void
    {
        if ($this->headOnly) {
            // HEAD: Content-Length already set by the caller; send no body.
            $this->response->end('');
            return;
        }

        $this->response->sendfile($path, $offset, $length);
    }
}
