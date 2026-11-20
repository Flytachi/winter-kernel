<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Sse\Fixtures;

use Flytachi\Winter\Kernel\Http\Contracts\StreamingResponse;
use Flytachi\Winter\Kernel\Http\Cookie\SetCookie;

/** A response that records what was written; it can play a client that has left. */
final class FakeStreamingResponse implements StreamingResponse
{
    public ?int $status = null;
    /** @var array<string, string> */
    public array $headers = [];
    /** @var list<string> */
    public array $chunks = [];
    public bool $ended = false;
    public bool $cut = false;

    /**
     * @param int|null $goneAfter Writes that succeed before the client is gone; null — never.
     * @param int|null $stallAfter Writes before the client stops reading: the next one
     *     waits as a full socket would, until the coroutine is cancelled.
     */
    public function __construct(private readonly ?int $goneAfter = null, private readonly ?int $stallAfter = null)
    {
    }

    public function status(int $code): void
    {
        $this->status = $code;
    }

    public function header(string $name, string $value): void
    {
        $this->headers[strtolower($name)] = $value;
    }

    public function cookie(SetCookie $cookie): void
    {
    }

    public function end(string $body = ''): void
    {
        $this->ended = true;
    }

    public function close(): void
    {
        $this->cut = true;
    }

    public function sendfile(string $path, int $offset = 0, int $length = 0): void
    {
    }

    public function write(string $chunk): bool
    {
        if ($this->goneAfter !== null && count($this->chunks) >= $this->goneAfter) {
            return false;
        }
        if ($this->stallAfter !== null && count($this->chunks) >= $this->stallAfter) {
            \Swoole\Coroutine::sleep(30);
            return false;
        }
        $this->chunks[] = $chunk;
        return true;
    }

    public function wire(): string
    {
        return implode('', $this->chunks);
    }
}
