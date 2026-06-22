<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request;

use Flytachi\Winter\K2\Http\Adapter\FpmRequest;
use Flytachi\Winter\K2\Http\Header;
use PHPUnit\Framework\TestCase;

/**
 * Global access to the request origin via Header (FPM mode).
 *
 * Header::init() snapshots scheme/host/port/baseUrl so they can be read from
 * anywhere without threading the HttpRequest through the call stack.
 */
class HeaderOriginTest extends TestCase
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

    private function init(array $server): void
    {
        $_SERVER = $server;
        Header::init(new FpmRequest());
    }

    // ── Origin getters ────────────────────────────────────────────────────────

    public function test_base_url_is_available_globally(): void
    {
        $this->init(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SERVER_PORT' => '443']);
        $this->assertSame('https://example.com', Header::getBaseUrl());
    }

    public function test_base_url_keeps_non_standard_port(): void
    {
        $this->init(['HTTP_HOST' => 'example.com', 'SERVER_PORT' => '8080']);
        $this->assertSame('http://example.com:8080', Header::getBaseUrl());
    }

    public function test_scheme_host_port_getters(): void
    {
        $this->init([
            'HTTP_HOST'              => 'backend.internal',
            'HTTP_X_FORWARDED_HOST'  => 'api.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT'  => '8443',
        ]);

        $this->assertSame('https', Header::getScheme());
        $this->assertSame('api.example.com', Header::getHost());
        $this->assertSame(8443, Header::getPort());
        $this->assertSame('https://api.example.com:8443', Header::getBaseUrl());
    }

    public function test_port_getter_returns_int(): void
    {
        $this->init(['HTTP_HOST' => 'example.com', 'SERVER_PORT' => '80']);
        $this->assertSame(80, Header::getPort());
    }

    // ── The origin snapshot must not clobber the raw Host header ───────────────

    public function test_raw_host_header_is_preserved(): void
    {
        $this->init(['HTTP_HOST' => 'example.com:8080', 'SERVER_PORT' => '8080']);

        // getHost() is the parsed host (no port); the raw Host header keeps its port
        $this->assertSame('example.com', Header::getHost());
        $this->assertSame('example.com:8080', Header::get('Host'));
    }

    // ── Re-init overwrites the previous snapshot ──────────────────────────────

    public function test_reinit_replaces_origin(): void
    {
        $this->init(['HTTP_HOST' => 'first.test']);
        $this->assertSame('http://first.test', Header::getBaseUrl());

        $this->init(['HTTP_HOST' => 'second.test', 'HTTPS' => 'on']);
        $this->assertSame('https://second.test', Header::getBaseUrl());
    }
}

