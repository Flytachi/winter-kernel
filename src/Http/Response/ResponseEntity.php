<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\Header;

/**
 * Spring-Boot-style response wrapper — works in both Swoole and FPM modes.
 *
 * Controller methods return a ResponseEntity; the Router serializes it to
 * the HttpResponse — controllers never call $response->end() themselves.
 *
 * Serialization respects the client's Accept header (content negotiation):
 *   Accept: application/json  → JSON
 *   Accept: application/xml   → XML
 *   Accept: text/html         → HTML (print_r fallback)
 *   Accept: *\/* or absent   → JSON (default)
 *
 * Static factory shortcuts:
 *   ResponseEntity::ok($data)
 *   ResponseEntity::created($data)
 *   ResponseEntity::noContent()
 *   ResponseEntity::notFound('message')
 *   ResponseEntity::status(HttpCode::ACCEPTED)->body($data)
 *
 * Custom headers:
 *   ResponseEntity::ok($data)->header('X-Request-Id', $id)
 */
class ResponseEntity implements Sendable
{
    private mixed $body    = null;
    private array $headers = [];

    private function __construct(private HttpCode $code) {}

    // ── Status factories ──────────────────────────────────────────────────────

    public static function status(HttpCode $code): static
    {
        return new static($code);
    }

    public static function ok(mixed $body = null): static
    {
        return (new static(HttpCode::OK))->body($body);
    }

    public static function created(mixed $body = null): static
    {
        return (new static(HttpCode::CREATED))->body($body);
    }

    public static function accepted(mixed $body = null): static
    {
        return (new static(HttpCode::ACCEPTED))->body($body);
    }

    public static function noContent(): static
    {
        return new static(HttpCode::NO_CONTENT);
    }

    public static function badRequest(mixed $body = null): static
    {
        return (new static(HttpCode::BAD_REQUEST))->body($body);
    }

    public static function unauthorized(mixed $body = null): static
    {
        return (new static(HttpCode::UNAUTHORIZED))->body($body);
    }

    public static function forbidden(mixed $body = null): static
    {
        return (new static(HttpCode::FORBIDDEN))->body($body);
    }

    public static function notFound(mixed $body = null): static
    {
        return (new static(HttpCode::NOT_FOUND))->body($body);
    }

    public static function conflict(mixed $body = null): static
    {
        return (new static(HttpCode::CONFLICT))->body($body);
    }

    public static function unprocessable(mixed $body = null): static
    {
        return (new static(HttpCode::UNPROCESSABLE_ENTITY))->body($body);
    }

    public static function internalError(mixed $body = null): static
    {
        return (new static(HttpCode::INTERNAL_SERVER_ERROR))->body($body);
    }

    // ── Builder ───────────────────────────────────────────────────────────────

    public function body(mixed $body): static
    {
        $this->body = $body;
        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getCode(): HttpCode { return $this->code; }
    public function getBody(): mixed    { return $this->body; }
    public function getHeaders(): array { return $this->headers; }

    // ── Serialization ─────────────────────────────────────────────────────────

    /**
     * Write this ResponseEntity to an HttpResponse (Swoole or FPM).
     * For structured data (array/object) respects the Accept header.
     * For scalar values always sends text/plain.
     */
    public function send(HttpResponse $response): void
    {
        $response->status($this->code->value);

        foreach ($this->headers as $name => $value) {
            $response->header($name, $value);
        }

        if ($this->code === HttpCode::NO_CONTENT || $this->body === null) {
            $response->end();
            return;
        }

        $body = $this->body;

        if (is_object($body) && method_exists($body, 'toArray')) {
            $body = $body->toArray();
        }

        if (is_array($body) || is_object($body)) {
            // Content negotiation — respect Accept header, default to JSON
            $contentType = AcceptHeaderParser::getBestMatch(Header::get('Accept'));
            if ($contentType === ContentType::UNDEFINED || $contentType === ContentType::HTML) {
                $contentType = ContentType::JSON;
            }
            $response->header('Content-Type', $contentType->headerFullValue());
            $response->end($contentType->serialize($body));
        } else {
            $response->header('Content-Type', 'text/plain; charset=utf-8');
            $response->end((string) $body);
        }
    }
}
