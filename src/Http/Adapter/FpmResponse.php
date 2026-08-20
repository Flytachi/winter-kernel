<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Adapter;

use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Cookie\SetCookie;

/**
 * HttpResponse adapter for PHP-FPM / Apache (CGI model).
 * Uses PHP's native header() / http_response_code() / echo.
 */
final class FpmResponse implements HttpResponse
{
    private bool $ended = false;

    /**
     * @param bool $headOnly suppress the body (HEAD request) while keeping headers
     */
    public function __construct(private readonly bool $headOnly = false)
    {
    }

    public function status(int $code): void
    {
        http_response_code($code);
    }

    public function header(string $name, string $value): void
    {
        header("{$name}: {$value}");
    }

    /**
     * `false` as the replace argument is the whole point: header() replaces by name by
     * default, so a second cookie written the ordinary way would silently drop the first.
     *
     * setcookie() is not used — it applies its own encoding and attribute spelling, and
     * the bytes would then differ from Swoole's.
     */
    public function cookie(SetCookie $cookie): void
    {
        header('Set-Cookie: ' . $cookie->toHeader(), false);
    }

    public function end(string $body = ''): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        if ($this->headOnly && $body !== '') {
            // HEAD: keep the Content-Length GET would report, drop the body.
            header('Content-Length: ' . strlen($body));
            return;
        }

        if ($body !== '') {
            echo $body;
        }
    }

    public function sendfile(string $path, int $offset = 0, int $length = 0): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        if ($this->headOnly) {
            // HEAD: Content-Length already set by the caller; send no body.
            return;
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return;
        }
        if ($offset > 0) {
            fseek($fp, $offset);
        }
        if ($length > 0) {
            $out = fopen('php://output', 'wb');
            stream_copy_to_stream($fp, $out, $length);
            fclose($out);
        } else {
            fpassthru($fp);
        }
        fclose($fp);
    }
}
