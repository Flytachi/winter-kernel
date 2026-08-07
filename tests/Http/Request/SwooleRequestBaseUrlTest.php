<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request;

use Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;

/**
 * Behavioural lock for SwooleRequest::getBaseUrl().
 *
 * Mirrors {@see FpmRequestBaseUrlTest}: standard ports (80/http, 443/https)
 * are dropped, others are explicit. Swoole has no native scheme flag, so
 * https is detected only through proxy headers (Forwarded / X-Forwarded-Proto).
 *
 * When ext-swoole is absent the swoole/ide-helper class stub is used — it
 * exposes the public `header`/`server` properties that the adapter reads.
 */
class SwooleRequestBaseUrlTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(Request::class, false)) {
            $stub = dirname(__DIR__, 3)
                . '/vendor/swoole/ide-helper/src/swoole/Swoole/Http/Request.php';
            if (!is_file($stub)) {
                self::markTestSkipped('Swoole\\Http\\Request unavailable (no ext-swoole, no ide-helper).');
            }
            require_once $stub;
        }
    }

    /**
     * @param array<string,string> $header
     * @param array<string,mixed>  $server
     */
    private function baseUrl(array $header, array $server = []): string
    {
        $req = new Request();
        $req->header = $header;
        $req->server = $server;
        return (new SwooleRequest($req))->getBaseUrl();
    }

    // ── Standard ports are dropped ────────────────────────────────────────────

    public function test_http_on_80_omits_port(): void
    {
        $this->assertSame(
            'http://example.com',
            $this->baseUrl(['host' => 'example.com'], ['server_port' => 80]),
        );
    }

    public function test_https_on_443_omits_port(): void
    {
        $this->assertSame(
            'https://example.com',
            $this->baseUrl(
                ['host' => 'example.com', 'x-forwarded-proto' => 'https'],
                ['server_port' => 443],
            ),
        );
    }

    // ── Non-standard ports are kept ───────────────────────────────────────────

    public function test_http_on_custom_port_keeps_port(): void
    {
        $this->assertSame(
            'http://example.com:8080',
            $this->baseUrl(['host' => 'example.com'], ['server_port' => 8080]),
        );
    }

    public function test_https_on_custom_port_keeps_port(): void
    {
        $this->assertSame(
            'https://example.com:8443',
            $this->baseUrl(
                ['host' => 'example.com', 'x-forwarded-proto' => 'https'],
                ['server_port' => 8443],
            ),
        );
    }

    // ── Scheme/port mismatches stay explicit ──────────────────────────────────

    public function test_http_on_443_keeps_port(): void
    {
        $this->assertSame(
            'http://example.com:443',
            $this->baseUrl(['host' => 'example.com'], ['server_port' => 443]),
        );
    }

    // ── Port carried by the Host header wins over server_port ─────────────────

    public function test_port_in_host_header_takes_precedence(): void
    {
        $this->assertSame(
            'http://example.com:9000',
            $this->baseUrl(['host' => 'example.com:9000'], ['server_port' => 80]),
        );
    }

    public function test_standard_port_in_host_header_is_dropped(): void
    {
        $this->assertSame(
            'https://example.com',
            $this->baseUrl(['host' => 'example.com:443', 'x-forwarded-proto' => 'https']),
        );
    }

    // ── Reverse-proxy headers ─────────────────────────────────────────────────

    public function test_x_forwarded_port_wins_over_server_port(): void
    {
        $this->assertSame(
            'https://example.com',
            $this->baseUrl(
                ['host' => 'example.com', 'x-forwarded-proto' => 'https', 'x-forwarded-port' => '443'],
                ['server_port' => 8443],
            ),
        );
    }

    public function test_forwarded_proto_https_without_forwarded_port_ignores_backend_default(): void
    {
        // Proxy sets the scheme but no x-forwarded-port; backend server_port 80
        // is the proxy→app hop, not the public port — must not produce :80.
        $this->assertSame(
            'https://example.com',
            $this->baseUrl(
                ['host' => 'example.com', 'x-forwarded-proto' => 'https'],
                ['server_port' => 80],
            ),
        );
    }

    public function test_forwarded_header_proto_and_host(): void
    {
        $this->assertSame(
            'https://api.example.com',
            $this->baseUrl(
                ['host' => 'backend.internal', 'forwarded' => 'proto=https;host=api.example.com'],
                ['server_port' => 443],
            ),
        );
    }

    // ── Defaults when no explicit port is present ─────────────────────────────

    public function test_http_defaults_to_80_and_omits_port(): void
    {
        $this->assertSame(
            'http://example.com',
            $this->baseUrl(['host' => 'example.com']),
        );
    }

    public function test_missing_host_falls_back_to_localhost(): void
    {
        $this->assertSame(
            'http://localhost',
            $this->baseUrl([], ['server_port' => 80]),
        );
    }

    // ── IPv6 hosts ────────────────────────────────────────────────────────────

    public function test_ipv6_host_with_custom_port(): void
    {
        $this->assertSame(
            'http://[::1]:8080',
            $this->baseUrl(['host' => '[::1]:8080']),
        );
    }
}
