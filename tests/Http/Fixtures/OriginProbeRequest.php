<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Fixtures;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Cookie\CookieParser;

/**
 * A request that reports a fixed origin and counts how often it is asked for one.
 *
 * The counter is the point: it turns "computed lazily" from a claim into an assertion.
 */
final class OriginProbeRequest implements HttpRequest
{
    public int $originCalls = 0;

    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $scheme = 'http',
        private readonly string $host = 'localhost',
        private readonly int $port = 80,
        private readonly string $baseUrl = 'http://localhost',
        private readonly array $headers = [],
    ) {
    }

    public function getScheme(): string
    {
        $this->originCalls++;
        return $this->scheme;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function getClientIp(): string
    {
        return '127.0.0.1';
    }

    public function getMethod(): string
    {
        return 'GET';
    }

    public function getUri(): string
    {
        return '/';
    }

    public function getQueryParams(): array
    {
        return [];
    }

    public function getParsedBody(): array
    {
        return [];
    }

    public function getRawBody(): string
    {
        return '';
    }

    /** Parsed from the `Cookie` header, exactly as the real adapters do. */
    public function getCookie(string $name): ?string
    {
        return $this->getCookies()[$name] ?? null;
    }

    public function getCookies(): array
    {
        return CookieParser::parse($this->getHeader('Cookie') ?? '');
    }

    public function getUploadedFiles(): array
    {
        return [];
    }

    public function getServerParam(string $key): ?string
    {
        return null;
    }

    public function getClientTimezone(): ?string
    {
        return null;
    }
}
