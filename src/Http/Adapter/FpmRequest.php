<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Adapter;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;

/**
 * HttpRequest adapter for PHP-FPM / Apache (CGI model).
 * Reads from superglobals — safe for single-request process lifecycle.
 *
 * Usage in index.php:
 *   $router->handle(new FpmRequest(), new FpmResponse());
 */
final class FpmRequest implements HttpRequest
{
    private readonly array  $headers;
    private ?string         $rawBody = null;

    public function __construct()
    {
        $this->headers = $this->extractHeaders();
    }

    public function getMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function getUri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }

    public function getQueryParams(): array
    {
        return $_GET;
    }

    public function getParsedBody(): array
    {
        return $_POST;
    }

    public function getRawBody(): string
    {
        return $this->rawBody ??= (file_get_contents('php://input') ?: '');
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getUploadedFiles(): array
    {
        $result = [];
        foreach ($_FILES as $field => $info) {
            if (is_array($info['name'])) {
                foreach (array_keys($info['name']) as $i) {
                    $result[$field][] = [
                        'name'     => $info['name'][$i],
                        'type'     => $info['type'][$i],
                        'tmp_name' => $info['tmp_name'][$i],
                        'error'    => $info['error'][$i],
                        'size'     => $info['size'][$i],
                    ];
                }
            } else {
                $result[$field] = $info;
            }
        }
        return $result;
    }

    public function getServerParam(string $key): ?string
    {
        return $_SERVER[$key] ?? null;
    }

    public function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_FORWARDED'])) {
            if (preg_match('/for=["\']?([^;,"\'\s\]]+)/i', $_SERVER['HTTP_FORWARDED'], $m)) {
                $ip = trim($m[1], '"\'[]');
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function extractHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
            return $headers;
        }

        // Fallback: extract from $_SERVER HTTP_* keys
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }
}
