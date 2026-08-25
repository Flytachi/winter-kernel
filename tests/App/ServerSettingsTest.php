<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Config\ServerSettings;
use PHPUnit\Framework\TestCase;

/**
 * ServerSettings holds the bind address (host/port) plus the raw Swoole options.
 * Host/port are the framework's default bind policy; only the tuning knobs come
 * from the environment.
 */
final class ServerSettingsTest extends TestCase
{
    public function test_default_bind_address(): void
    {
        $s = ServerSettings::fromEnv();

        self::assertSame('0.0.0.0', $s->getHost());
        self::assertSame(8000, $s->getPort());
    }

    public function test_seeded_bind_address(): void
    {
        $s = ServerSettings::fromEnv('127.0.0.1', 9000);

        self::assertSame('127.0.0.1', $s->getHost());
        self::assertSame(9000, $s->getPort());
    }

    public function test_setters_override_seed(): void
    {
        $s = ServerSettings::fromEnv('0.0.0.0', 8000)->host('10.0.0.1')->port(1234);

        self::assertSame('10.0.0.1', $s->getHost());
        self::assertSame(1234, $s->getPort());
    }

    public function test_host_and_port_are_not_swoole_options(): void
    {
        $s = ServerSettings::fromEnv('1.2.3.4', 80);
        $options = $s->toArray();

        self::assertArrayNotHasKey('host', $options);
        self::assertArrayNotHasKey('port', $options);
    }

    /**
     * Not a preference — the adapters read the raw `Cookie` header, and Swoole removes it
     * from `$request->header` the moment it parses cookies itself. Left at Swoole's default
     * every cookie under Swoole reads as absent.
     */
    public function test_swooles_own_cookie_parsing_is_off(): void
    {
        self::assertFalse(ServerSettings::fromEnv()->toArray()['http_parse_cookie']);
    }

    /** A raw option like any other: the application has the last word, and the adapter copes. */
    public function test_an_application_may_switch_cookie_parsing_back_on(): void
    {
        $s = ServerSettings::fromEnv()->set('http_parse_cookie', true);

        self::assertTrue($s->toArray()['http_parse_cookie']);
    }

    public function test_env_seeds_tuning_options(): void
    {
        $_ENV['SERVER_WORKERS'] = '4';
        try {
            $s = ServerSettings::fromEnv();
            self::assertSame(4, $s->toArray()['worker_num']);
        } finally {
            unset($_ENV['SERVER_WORKERS']);
        }
    }

    public function test_fluent_options(): void
    {
        $s = ServerSettings::fromEnv()
            ->workers(8)
            ->maxRequest(5000)
            ->set('ssl_cert_file', '/etc/ssl/app.pem');
        $options = $s->toArray();

        self::assertSame(8, $options['worker_num']);
        self::assertSame(5000, $options['max_request']);
        self::assertSame('/etc/ssl/app.pem', $options['ssl_cert_file']);
    }
}
