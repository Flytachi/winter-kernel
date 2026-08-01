<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Route\Fixtures;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Stereotype\Middleware;

/**
 * Writes its own name into a shared trace on each hook, so a test can assert the order
 * the router ran them in. `after()` also appends to the result, which shows whether the
 * unwinding really happens in reverse.
 */
class RecordingMiddleware extends Middleware
{
    /** @var list<string> */
    public static array $trace = [];

    public static function reset(): void
    {
        self::$trace = [];
    }

    protected function tag(): string
    {
        return 'mw';
    }

    public function before(HttpRequest $request, HttpResponse $response): void
    {
        self::$trace[] = 'before:' . $this->tag();
    }

    public function after(mixed $result): mixed
    {
        self::$trace[] = 'after:' . $this->tag();

        return is_string($result) ? $result . '|' . $this->tag() : $result;
    }
}

final class FirstMiddleware extends RecordingMiddleware
{
    protected function tag(): string
    {
        return 'first';
    }
}

final class SecondMiddleware extends RecordingMiddleware
{
    protected function tag(): string
    {
        return 'second';
    }
}
