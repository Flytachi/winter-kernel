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

    public function test_server_header_defaults_to_winter(): void
    {
        self::assertSame('Winter', ServerSettings::fromEnv()->getServerHeader());
    }

    public function test_env_sets_the_server_header(): void
    {
        $_ENV['SERVER_HEADER'] = ' Orders API ';
        try {
            self::assertSame('Orders API', ServerSettings::fromEnv()->getServerHeader());
        } finally {
            unset($_ENV['SERVER_HEADER']);
        }
    }

    /** Set but empty: no header — not the default. */
    public function test_an_empty_env_value_sends_no_server_header(): void
    {
        $_ENV['SERVER_HEADER'] = '';
        try {
            self::assertSame('', ServerSettings::fromEnv()->getServerHeader());
        } finally {
            unset($_ENV['SERVER_HEADER']);
        }
    }

    public function test_the_server_header_is_not_a_swoole_option(): void
    {
        $s = ServerSettings::fromEnv()->serverHeader('Mine');

        self::assertSame('Mine', $s->getServerHeader());
        self::assertArrayNotHasKey('http_server_header', $s->toArray());
        self::assertNotContains('Mine', $s->toArray());
    }

    /** On, level 1 and a 1K threshold — Swoole's own threshold is about 20 bytes. */
    public function test_compression_defaults(): void
    {
        $s = ServerSettings::fromEnv();
        $options = $s->toArray();

        self::assertTrue($s->isCompressionEnabled());
        self::assertSame(1, $s->getCompressionLevel());
        self::assertSame(1024, $s->getCompressionMinLength());
        self::assertTrue($options['http_compression']);
        self::assertSame(1, $options['http_compression_level']);
        self::assertSame(1024, $options['http_compression_min_length']);
    }

    /**
     * env() hands `false` over as a boolean and digits as an integer; `off` and sizes
     * with a unit arrive as strings.
     *
     * @return array<string, array{array<string, string>, bool, int, int}>
     */
    public static function compressionEnv(): array
    {
        return [
            'off'             => [['SERVER_COMPRESSION' => 'false'], false, 1, 1024],
            'off as word'     => [['SERVER_COMPRESSION' => 'off'], false, 1, 1024],
            'off as digit'    => [['SERVER_COMPRESSION' => '0'], false, 1, 1024],
            'level'           => [['SERVER_COMPRESSION_LEVEL' => '5'], true, 5, 1024],
            'level too high'  => [['SERVER_COMPRESSION_LEVEL' => '12'], true, 9, 1024],
            'level not a number' => [['SERVER_COMPRESSION_LEVEL' => 'max'], true, 1, 1024],
            'min in bytes'    => [['SERVER_COMPRESSION_MIN' => '512'], true, 1, 512],
            'min with a unit' => [['SERVER_COMPRESSION_MIN' => '4K'], true, 1, 4096],
            'min zero'        => [['SERVER_COMPRESSION_MIN' => '0'], true, 1, 0],
            'min not a size'  => [['SERVER_COMPRESSION_MIN' => 'big'], true, 1, 1024],
        ];
    }

    /**
     * @param array<string, string> $env
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('compressionEnv')]
    public function test_env_sets_compression(array $env, bool $enabled, int $level, int $minLength): void
    {
        $_ENV = $env + $_ENV;
        try {
            $s = ServerSettings::fromEnv();
            self::assertSame($enabled, $s->isCompressionEnabled());
            self::assertSame($level, $s->getCompressionLevel());
            self::assertSame($minLength, $s->getCompressionMinLength());
        } finally {
            foreach (array_keys($env) as $name) {
                unset($_ENV[$name]);
            }
        }
    }

    public function test_fluent_compression(): void
    {
        $s = ServerSettings::fromEnv()->compression(false)->compressionLevel(6)->compressionMinLength('2K');
        $options = $s->toArray();

        self::assertFalse($options['http_compression']);
        self::assertSame(6, $options['http_compression_level']);
        self::assertSame(2048, $options['http_compression_min_length']);
    }

    /** The code has the last word over `.env`. */
    public function test_a_configurer_overrides_compression_env(): void
    {
        $_ENV['SERVER_COMPRESSION'] = 'false';
        try {
            self::assertTrue(ServerSettings::fromEnv()->compression(true)->isCompressionEnabled());
        } finally {
            unset($_ENV['SERVER_COMPRESSION']);
        }
    }

    public function test_a_compression_level_out_of_range_is_refused(): void
    {
        $this->expectException(\Flytachi\Winter\Kernel\App\ApplicationConfigException::class);
        ServerSettings::fromEnv()->compressionLevel(10);
    }

    public function test_a_compression_threshold_that_is_not_a_size_is_refused(): void
    {
        $this->expectException(\Flytachi\Winter\Kernel\App\ApplicationConfigException::class);
        ServerSettings::fromEnv()->compressionMinLength('1 kilobyte');
    }

    /** env() turns digits into an integer and `false` into a boolean. */
    public function test_server_header_survives_envs_type_juggling(): void
    {
        try {
            $_ENV['SERVER_HEADER'] = '2026';
            self::assertSame('2026', ServerSettings::fromEnv()->getServerHeader());
            $_ENV['SERVER_HEADER'] = 'false';
            self::assertSame('', ServerSettings::fromEnv()->getServerHeader());
        } finally {
            unset($_ENV['SERVER_HEADER']);
        }
    }
}
