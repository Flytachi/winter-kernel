<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request;

use Flytachi\Winter\Kernel\Http\Adapter\FpmRequest;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural lock for FpmRequest::getBaseUrl().
 *
 * Standard ports must be omitted (80 for http, 443 for https); any other
 * port is rendered explicitly. The port is derived from X-Forwarded-Port,
 * the Host header, SERVER_PORT, then the scheme default — in that order.
 */
class FpmRequestBaseUrlTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    private function baseUrl(array $server): string
    {
        $_SERVER = $server;
        return (new FpmRequest())->getBaseUrl();
    }

    // ── Standard ports are dropped ────────────────────────────────────────────

    public function test_http_on_80_omits_port(): void
    {
        $this->assertSame(
            'http://example.com',
            $this->baseUrl(['HTTP_HOST' => 'example.com', 'SERVER_PORT' => '80']),
        );
    }

    public function test_https_on_443_omits_port(): void
    {
        $this->assertSame(
            'https://example.com',
            $this->baseUrl(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SERVER_PORT' => '443']),
        );
    }

    // ── Non-standard ports are kept ───────────────────────────────────────────

    public function test_http_on_custom_port_keeps_port(): void
    {
        $this->assertSame(
            'http://example.com:8080',
            $this->baseUrl(['HTTP_HOST' => 'example.com', 'SERVER_PORT' => '8080']),
        );
    }

    public function test_https_on_custom_port_keeps_port(): void
    {
        $this->assertSame(
            'https://example.com:8443',
            $this->baseUrl(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SERVER_PORT' => '8443']),
        );
    }

    // ── Scheme/port mismatches ────────────────────────────────────────────────
    // http on 443 is kept explicit (plain HTTP on 443 is reachable). https on 80
    // is a contradiction (no TLS on :80) — the port is dropped to the https default.

    public function test_http_on_443_keeps_port(): void
    {
        $this->assertSame(
            'http://example.com:443',
            $this->baseUrl(['HTTP_HOST' => 'example.com', 'SERVER_PORT' => '443']),
        );
    }

    public function test_https_on_80_drops_contradictory_port(): void
    {
        $this->assertSame(
            'https://example.com',
            $this->baseUrl(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SERVER_PORT' => '80']),
        );
    }

    // ── Port carried by the Host header wins over SERVER_PORT ─────────────────

    public function test_port_in_host_header_takes_precedence(): void
    {
        $this->assertSame(
            'http://example.com:9000',
            $this->baseUrl(['HTTP_HOST' => 'example.com:9000', 'SERVER_PORT' => '80']),
        );
    }

    public function test_standard_port_in_host_header_is_dropped(): void
    {
        $this->assertSame(
            'https://example.com',
            $this->baseUrl(['HTTP_HOST' => 'example.com:443', 'HTTPS' => 'on']),
        );
    }

    // ── Reverse-proxy headers ─────────────────────────────────────────────────

    public function test_forwarded_proto_https_with_forwarded_port_443(): void
    {
        $this->assertSame(
            'https://example.com',
            $this->baseUrl([
                'HTTP_HOST'              => 'example.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_PORT'  => '443',
            ]),
        );
    }

    public function test_forwarded_proto_https_with_custom_forwarded_port(): void
    {
        $this->assertSame(
            'https://example.com:8443',
            $this->baseUrl([
                'HTTP_HOST'              => 'example.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_PORT'  => '8443',
            ]),
        );
    }

    public function test_forwarded_proto_https_without_forwarded_port_ignores_backend_default(): void
    {
        // TLS-terminating proxy sets the scheme but no X-Forwarded-Port; the
        // backend SERVER_PORT (80) is the proxy→app hop, not the public port.
        $this->assertSame(
            'https://example.com',
            $this->baseUrl([
                'HTTP_HOST'              => 'example.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'SERVER_PORT'            => '80',
            ]),
        );
    }

    public function test_forwarded_proto_https_keeps_nonstandard_backend_port(): void
    {
        // A non-default SERVER_PORT behind a proxy is intentional — keep it.
        $this->assertSame(
            'https://example.com:8443',
            $this->baseUrl([
                'HTTP_HOST'              => 'example.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'SERVER_PORT'            => '8443',
            ]),
        );
    }

    public function test_forwarded_host_header_is_used(): void
    {
        $this->assertSame(
            'https://api.example.com',
            $this->baseUrl([
                'HTTP_HOST'              => 'backend.internal',
                'HTTP_X_FORWARDED_HOST'  => 'api.example.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_PORT'  => '443',
            ]),
        );
    }

    // ── Defaults when no explicit port is present ─────────────────────────────

    public function test_http_defaults_to_80_and_omits_port(): void
    {
        $this->assertSame(
            'http://example.com',
            $this->baseUrl(['HTTP_HOST' => 'example.com']),
        );
    }

    public function test_https_defaults_to_443_and_omits_port(): void
    {
        $this->assertSame(
            'https://example.com',
            $this->baseUrl(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on']),
        );
    }

    // ── IPv6 hosts ────────────────────────────────────────────────────────────

    public function test_ipv6_host_with_custom_port(): void
    {
        $this->assertSame(
            'http://[::1]:8080',
            $this->baseUrl(['HTTP_HOST' => '[::1]:8080']),
        );
    }

    public function test_ipv6_host_on_standard_port(): void
    {
        $this->assertSame(
            'http://[::1]',
            $this->baseUrl(['HTTP_HOST' => '[::1]', 'SERVER_PORT' => '80']),
        );
    }
}
