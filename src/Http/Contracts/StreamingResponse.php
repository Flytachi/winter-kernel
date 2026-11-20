<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Contracts;

/**
 * A response that can be written in parts while the connection stays open — what a stream
 * ({@see \Flytachi\Winter\Kernel\Http\Sse\SseEmitter}) writes to. The Swoole HTTP server's.
 */
interface StreamingResponse extends HttpResponse
{
    /**
     * Writes a part of the body without ending the response; the headers go with the first.
     *
     * @return bool False when the client is gone.
     */
    public function write(string $chunk): bool;

    /**
     * Cuts the connection at once, without ending the response: what still waits in its
     * buffer for a client that stopped reading is dropped, and nothing waits for it.
     */
    public function close(): void;
}
