<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route\Fixtures;

use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Cookie\SetCookie;

/**
 * Captures what the router wrote instead of sending it, so a test can assert on the
 * status, headers and body of a dispatched request.
 */
final class FakeResponse implements HttpResponse
{
    public ?int $status = null;
    /** @var array<string, string> */
    public array $headers = [];
    public ?string $body = null;
    public bool $ended = false;
    public ?string $sentFile = null;
    /** @var list<string> Set-Cookie values, in the order they were written. */
    public array $cookies = [];

    public function status(int $code): void
    {
        $this->status = $code;
    }

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function cookie(SetCookie $cookie): void
    {
        $this->cookies[] = $cookie->toHeader();
    }

    public function end(string $body = ''): void
    {
        $this->body  = $body;
        $this->ended = true;
    }

    public function sendfile(string $path, int $offset = 0, int $length = 0): void
    {
        $this->sentFile = $path;
        $this->ended    = true;
    }

    /** Case-insensitive header lookup, as a client would see it. */
    public function header_(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** The decoded JSON body, or null when the body is absent or not JSON. */
    public function json(): mixed
    {
        return $this->body === null ? null : json_decode($this->body, true);
    }
}
