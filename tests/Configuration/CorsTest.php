<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Configuration;

use Flytachi\Winter\K2\Http\Cors;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CorsTest extends TestCase
{
    protected function setUp(): void
    {
        self::resetState();
    }

    protected function tearDown(): void
    {
        self::resetState();
    }

    private static function resetState(): void
    {
        $prop = (new ReflectionClass(Cors::class))->getProperty('config');
        $prop->setValue(null, null);
    }

    // ── default state ─────────────────────────────────────────────────────────

    public function test_get_config_returns_null_before_configure(): void
    {
        self::assertNull(Cors::getConfig());
    }

    // ── configure() round-trips ───────────────────────────────────────────────

    public function test_configure_with_no_args_stores_all_defaults(): void
    {
        Cors::configure();

        self::assertSame([
            'origins'       => [],
            'allowHeaders'  => [],
            'exposeHeaders' => [],
            'credentials'   => false,
            'maxAge'        => 0,
            'vary'          => [],
        ], Cors::getConfig());
    }

    public function test_configure_round_trips_all_parameters(): void
    {
        Cors::configure(
            origins:       ['https://app.example.com', 'https://admin.example.com'],
            allowHeaders:  ['Content-Type', 'Authorization'],
            exposeHeaders: ['X-Request-Id'],
            credentials:   true,
            maxAge:        3600,
            vary:          ['Accept-Language'],
        );

        $cfg = Cors::getConfig();

        self::assertSame(['https://app.example.com', 'https://admin.example.com'], $cfg['origins']);
        self::assertSame(['Content-Type', 'Authorization'], $cfg['allowHeaders']);
        self::assertSame(['X-Request-Id'], $cfg['exposeHeaders']);
        self::assertTrue($cfg['credentials']);
        self::assertSame(3600, $cfg['maxAge']);
        self::assertSame(['Accept-Language'], $cfg['vary']);
    }

    public function test_configure_overwrites_previous_call(): void
    {
        Cors::configure(origins: ['https://first.example.com']);
        self::assertSame(['https://first.example.com'], Cors::getConfig()['origins']);

        Cors::configure(origins: ['https://second.example.com']);
        self::assertSame(['https://second.example.com'], Cors::getConfig()['origins']);
    }

    public function test_configure_credentials_default_is_false(): void
    {
        Cors::configure(origins: ['https://app.example.com']);
        self::assertFalse(Cors::getConfig()['credentials']);
    }

    public function test_configure_can_enable_credentials_with_explicit_origin(): void
    {
        // The framework does not enforce the credentials+wildcard ban itself —
        // browsers do. Configure stores whatever you give it.
        Cors::configure(origins: ['https://app.example.com'], credentials: true);
        self::assertTrue(Cors::getConfig()['credentials']);
        self::assertSame(['https://app.example.com'], Cors::getConfig()['origins']);
    }

    public function test_configure_with_empty_origins_remains_wildcard_array(): void
    {
        Cors::configure();
        self::assertSame([], Cors::getConfig()['origins']);
    }
}
