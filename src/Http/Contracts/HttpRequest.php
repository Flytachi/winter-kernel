<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Contracts;

/**
 * Unified HTTP request abstraction.
 *
 * Implemented by:
 *   - SwooleRequest  — wraps Swoole\Http\Request (coroutine-safe)
 *   - FpmRequest     — wraps $_SERVER / $_GET / $_POST / php://input
 *
 * All K2 internals (Router, ParameterResolver, RequestObject, Middleware)
 * depend only on this interface — never on a concrete transport.
 */
interface HttpRequest
{
    /** HTTP method in uppercase: GET, POST, PUT, etc. */
    public function getMethod(): string;

    /** Request URI without host, e.g. /users/42?page=1 */
    public function getUri(): string;

    /** Parsed query string as associative array ($_GET equivalent). */
    public function getQueryParams(): array;

    /** Parsed body for form submissions ($_POST equivalent). */
    public function getParsedBody(): array;

    /** Raw request body bytes (for JSON, XML, etc.). */
    public function getRawBody(): string;

    /**
     * Single header value by name (case-insensitive).
     * Returns null if header is absent.
     */
    public function getHeader(string $name): ?string;

    /**
     * All headers as an associative array.
     * Keys are lower-cased header names.
     */
    public function getHeaders(): array;

    /** Uploaded files ($_FILES equivalent). */
    public function getUploadedFiles(): array;

    /** Server / environment variable (e.g. 'remote_addr', 'request_method'). */
    public function getServerParam(string $key): ?string;

    /** Resolved client IP address (respects X-Forwarded-For / Forwarded). */
    public function getClientIp(): string;
}
