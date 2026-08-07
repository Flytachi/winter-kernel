<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Contracts;

/**
 * Unified HTTP request abstraction.
 *
 * Implemented by:
 *   - SwooleRequest  — wraps Swoole\Http\Request (coroutine-safe)
 *   - FpmRequest     — wraps $_SERVER / $_GET / $_POST / php://input
 *
 * All kernel internals (Router, ParameterResolver, Middleware)
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

    /**
     * Server / environment variable (e.g. 'remote_addr', 'request_method').
     *
     * Not every one of them is a string, which is why the return type is not `?string`:
     * Swoole stores `server_port`, `remote_port`, `request_time` and `master_time` as
     * integers and `request_time_float` as a float, and PHP does the same for
     * `REQUEST_TIME` and `REQUEST_TIME_FLOAT` under FPM. Asking for a port used to fail
     * with a `TypeError` under `strict_types` rather than answer.
     */
    public function getServerParam(string $key): string|int|float|null;

    /** Resolved client IP address (respects X-Forwarded-For / Forwarded). */
    public function getClientIp(): string;

    /**
     * Client-supplied IANA timezone (e.g. 'Asia/Tokyo') from the
     * `Timezone` or `X-Timezone` header, validated against
     * timezone_identifiers_list(). Returns null if absent or unknown.
     */
    public function getClientTimezone(): ?string;

    /**
     * Request scheme — 'http' or 'https'.
     * Honours `Forwarded` (RFC 7239) → `X-Forwarded-Proto` → direct server flag.
     * Proxy headers are trusted unconditionally — strip them at the edge
     * if the application is not behind a reverse proxy.
     */
    public function getScheme(): string;

    /**
     * Hostname only, without port — as the client addressed the server.
     * Honours `Forwarded: host=` → `X-Forwarded-Host` → `Host` header → `SERVER_NAME`.
     * Proxy headers are trusted unconditionally.
     */
    public function getHost(): string;

    /**
     * Resolved request port — as the client addressed the server.
     * Honours `X-Forwarded-Port` → port part of forwarded/Host header → server port.
     * Falls back to 443 for https / 80 for http when nothing else is available.
     *
     * A backend server port of 80 under an https scheme is treated as noise
     * (a TLS-terminating proxy hop or a misconfigured HTTPS flag) and replaced
     * by the https default 443 — it is never reported as an unreachable
     * https:80. Every other port, including a non-standard one, is kept as-is;
     * in particular http on 443 is reported unchanged.
     */
    public function getPort(): int;

    /**
     * Convenience base URL `scheme://host[:port]`, omitting standard ports
     * (80 for http, 443 for https). Equivalent to combining
     * getScheme() / getHost() / getPort().
     *
     * Because getPort() collapses a contradictory https/80 to the https
     * default, an https base URL never carries `:80`. A plain-http URL on
     * `:443` is kept explicit (it is reachable), as are all non-standard
     * ports (e.g. `:8443`).
     */
    public function getBaseUrl(): string;
}
