<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Adapter;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Swoole\Http\Request;

/**
 * HttpRequest adapter for the Swoole runtime.
 *
 * Usage in server.php:
 *   $server->on('request', function (Request $req, Response $res) use ($router) {
 *       $router->handle(new SwooleRequest($req), new SwooleResponse($res));
 *   });
 */
final class SwooleRequest implements HttpRequest
{
    public function __construct(private readonly Request $request)
    {
    }

    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    public function getUri(): string
    {
        return $this->request->server['request_uri'] ?? '/';
    }

    public function getQueryParams(): array
    {
        return $this->request->get ?? [];
    }

    public function getParsedBody(): array
    {
        return $this->request->post ?? [];
    }

    public function getRawBody(): string
    {
        return $this->request->rawContent() ?: '';
    }

    public function getHeader(string $name): ?string
    {
        return $this->request->header[strtolower($name)] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->request->header ?? [];
    }

    public function getUploadedFiles(): array
    {
        return $this->request->files ?? [];
    }

    public function getServerParam(string $key): ?string
    {
        return $this->request->server[$key] ?? null;
    }

    public function getClientIp(): string
    {
        $h = $this->request->header ?? [];

        if (!empty($h['forwarded'])) {
            if (preg_match('/for=["\']?([^;,"\'\s\]]+)/i', $h['forwarded'], $m)) {
                $ip = trim($m[1], '"\'[]');
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        if (!empty($h['x-real-ip'])) {
            $ip = trim($h['x-real-ip']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        if (!empty($h['x-forwarded-for'])) {
            foreach (explode(',', $h['x-forwarded-for']) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $this->request->server['remote_addr'] ?? '127.0.0.1';
    }

    /** Access the underlying Swoole request when needed (e.g. for file streaming). */
    public function getSwooleRequest(): Request
    {
        return $this->request;
    }
}
