<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Config\ServerSettings;
use Flytachi\Winter\Kernel\Http\ProxyHeader;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\TestCase;

/** Trusted proxies are set in code only: the application decides where the list comes from. */
final class ServerTrustedProxiesTest extends TestCase
{
    public function test_nobody_is_trusted_unless_set(): void
    {
        $proxies = ServerSettings::fromEnv()->getTrustedProxies();

        self::assertTrue($proxies->isEmpty());
        self::assertSame('none — forwarded headers ignored', WinterApplication::proxiesSummary($proxies));
    }

    public function test_the_environment_does_not_set_it(): void
    {
        $_ENV['SERVER_TRUSTED_PROXIES'] = '*';
        putenv('SERVER_TRUSTED_PROXIES=*');
        try {
            self::assertTrue(ServerSettings::fromEnv()->getTrustedProxies()->isEmpty());
        } finally {
            unset($_ENV['SERVER_TRUSTED_PROXIES']);
            putenv('SERVER_TRUSTED_PROXIES');
        }
    }

    public function test_a_later_call_replaces_the_list(): void
    {
        $settings = ServerSettings::fromEnv()->trustedProxies('10.0.0.0/8')->trustedProxies('172.16.0.0/12', '::1');

        self::assertSame(['172.16.0.0/12', '::1'], $settings->getTrustedProxies()->list());
        self::assertSame('172.16.0.0/12, ::1 (X-Forwarded-*)', WinterApplication::proxiesSummary($settings->getTrustedProxies()));
        self::assertTrue($settings->trustedProxies()->getTrustedProxies()->isEmpty());
    }

    public function test_a_star_is_shown_as_every_peer(): void
    {
        $proxies = ServerSettings::fromEnv()->trustedProxies('*')->getTrustedProxies();

        self::assertSame('* (every peer) (X-Forwarded-*)', WinterApplication::proxiesSummary($proxies));
    }

    public function test_a_wrong_value_stops_the_boot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'nginx'");
        ServerSettings::fromEnv()->trustedProxies('nginx');
    }

    public function test_the_header_families_are_set_apart_and_in_any_order(): void
    {
        $settings = ServerSettings::fromEnv()
            ->trustedHeaders(ProxyHeader::XForwarded, ProxyHeader::XRealIp)
            ->trustedProxies('10.0.0.0/8');

        self::assertTrue($settings->getTrustedProxies()->believes(ProxyHeader::XRealIp));
        self::assertFalse($settings->getTrustedProxies()->believes(ProxyHeader::Forwarded));
        self::assertSame('10.0.0.0/8 (X-Forwarded-*, X-Real-IP)', WinterApplication::proxiesSummary($settings->getTrustedProxies()));
    }

    public function test_no_header_family_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerSettings::fromEnv()->trustedHeaders();
    }
}
