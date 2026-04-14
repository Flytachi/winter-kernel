<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Adapter;

use Flytachi\Winter\K2\Http\Contracts\HttpResponse;

/**
 * HttpResponse adapter for PHP-FPM / Apache (CGI model).
 * Uses PHP's native header() / http_response_code() / echo.
 */
final class FpmResponse implements HttpResponse
{
    private bool $ended = false;

    public function status(int $code): void
    {
        http_response_code($code);
    }

    public function header(string $name, string $value): void
    {
        header("{$name}: {$value}");
    }

    public function end(string $body = ''): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        if ($body !== '') {
            echo $body;
        }
    }
}
