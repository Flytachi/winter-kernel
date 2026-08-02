<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route\Fixtures;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;

/**
 * A request the router can dispatch without a live SAPI. Only method, URI and headers
 * matter to routing; everything else answers with a harmless default.
 */
final class FakeRequest implements HttpRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $method = 'GET',
        private readonly string $uri = '/',
        private readonly array $headers = [],
        private readonly array $query = [],
        private readonly array $body = [],
    ) {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function getParsedBody(): array
    {
        return $this->body;
    }

    public function getRawBody(): string
    {
        return '';
    }

    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getUploadedFiles(): array
    {
        return [];
    }

    public function getServerParam(string $key): ?string
    {
        return null;
    }

    public function getClientIp(): string
    {
        return '127.0.0.1';
    }

    public function getClientTimezone(): ?string
    {
        return null;
    }

    public function getScheme(): string
    {
        return 'http';
    }

    public function getHost(): string
    {
        return 'localhost';
    }

    public function getPort(): int
    {
        return 80;
    }

    public function getBaseUrl(): string
    {
        return 'http://localhost';
    }
}
