<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Config\ServerSettings;
use Flytachi\Winter\Kernel\App\Config\WebConfigurerAdapter;
use Flytachi\Winter\Kernel\App\ListenFlags;
use Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures\EchoSocket;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Where a listening component binds: what it was declared with, then the launch flag
 * named after it, then — for the HTTP server — its configurer, which has the last word.
 *
 * A flag that does not say whose address it is, or names nobody, stops the boot: with an
 * HTTP server and a WebSocket a bare `--port` names neither, and an ignored flag leaves
 * the application on an address nobody asked for.
 */
final class ListenFlagsTest extends TestCase
{
    /** @return list<Component> */
    private static function app(): array
    {
        return [
            Component::http(port: 8000),
            Component::websocket(EchoSocket::class, 8001),
            Component::process('Main\\Proc\\Orders'),
        ];
    }

    /**
     * @param list<string> $flags
     * @return list<Component>
     */
    private static function launch(array $flags): array
    {
        return ListenFlags::apply(self::app(), ApplicationArguments::parse(['call', 'run', ...$flags]));
    }

    public function test_without_flags_the_declared_addresses_stand(): void
    {
        [$http, $socket] = self::launch([]);

        self::assertSame(['0.0.0.0', 8000], [$http->host, $http->port]);
        self::assertSame(['0.0.0.0', 8001], [$socket->host, $socket->port]);
    }

    public function test_a_flag_moves_only_the_component_it_names(): void
    {
        [$http, $socket] = self::launch(['--http.port=9000', '--http.host=127.0.0.1']);

        self::assertSame(['127.0.0.1', 9000], [$http->host, $http->port]);
        self::assertSame(['0.0.0.0', 8001], [$socket->host, $socket->port]);
    }

    /** By the name `call application info` shows, in any letter case. */
    public function test_a_websocket_is_named_after_its_class(): void
    {
        [, $socket] = self::launch(['--EchoSocket.port=9101', '--echosocket.host=127.0.0.1']);

        self::assertSame(['127.0.0.1', 9101], [$socket->host, $socket->port]);
    }

    public function test_other_options_are_left_alone(): void
    {
        $components = self::launch(['--watcher', '--no-banner', '--timeout=5']);

        self::assertSame(8000, $components[0]->port);
    }

    /** @return array<string, array{list<string>, string}> */
    public static function refused(): array
    {
        return [
            'bare port'        => [['--port=9000'], '--port does not say whose port it is: name the component. This application has: --http.port, --echosocket.port.'],
            'bare host'        => [['--host=127.0.0.1'], '--host does not say whose host it is: name the component. This application has: --http.host, --echosocket.host.'],
            'unknown name'     => [['--chta.port=9000'], '--chta.port: no listening component is named chta. This application has: --http.port, --echosocket.port.'],
            'not listening'    => [['--orders.port=9000'], '--orders.port: Orders does not listen on a port.'],
            'not a number'     => [['--http.port=abc'], '--http.port must be a port, 1–65535: abc given.'],
            'out of range'     => [['--http.port=70000'], '--http.port must be a port, 1–65535: 70000 given.'],
            'zero'             => [['--http.port=0'], '--http.port must be a port, 1–65535: 0 given.'],
            'no value'         => [['--http.port'], '--http.port needs a value'],
            'empty host'       => [['--http.host='], '--http.host needs a value'],
        ];
    }

    /** @param list<string> $flags */
    #[DataProvider('refused')]
    public function test_a_flag_that_names_no_listener_stops_the_boot(array $flags, string $message): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage($message);
        self::launch($flags);
    }

    public function test_a_bare_port_is_refused_even_with_one_listener(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('This application has: --http.port.');
        ListenFlags::apply([Component::http()], ApplicationArguments::parse(['call', 'run', '--port=9000']));
    }

    public function test_given_says_whether_the_launch_named_the_field(): void
    {
        $args = ApplicationArguments::parse(['call', 'run', '--HTTP.port=9000']);
        $http = Component::http();

        self::assertTrue(ListenFlags::given($args, $http, 'port'));
        self::assertFalse(ListenFlags::given($args, $http, 'host'));
    }

    // ── With the HTTP server's configurer ────────────────────────────────────

    /**
     * @param list<string> $flags
     * @param class-string|null $configurer
     * @return array{ServerSettings, list<string>}
     */
    private static function serverSettings(array $flags, ?string $configurer): array
    {
        $args = ApplicationArguments::parse(['call', 'run', ...$flags]);
        [$http] = ListenFlags::apply([Component::http(port: 8000)], $args);
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . $message;
            }
        };

        $container = new ReflectionProperty(WinterApplication::class, 'container');
        $configured = new ReflectionProperty(WinterApplication::class, 'serverConfigurer');
        $container->setValue(null, Container::init());
        $configured->setValue(null, $configurer);
        try {
            $settings = new ReflectionMethod(WinterApplication::class, 'buildServerSettings')
                ->invoke(null, $http, $args, $logger);
        } finally {
            $container->setValue(null, null);
            $configured->setValue(null, null);
        }
        return [$settings, $logger->lines];
    }

    public function test_the_flag_reaches_the_server(): void
    {
        [$settings, $log] = self::serverSettings(['--http.port=9000'], null);

        self::assertSame(9000, $settings->getPort());
        self::assertSame([], $log);
    }

    public function test_the_configurer_has_the_last_word(): void
    {
        [$settings, $log] = self::serverSettings([], PinnedPortConfig::class);

        self::assertSame(8080, $settings->getPort());
        self::assertSame([], $log, 'no flag was overridden');
    }

    /** Code overrides the flag on purpose, but the operator who typed it is told. */
    public function test_a_configurer_overriding_a_flag_says_so(): void
    {
        [$settings, $log] = self::serverSettings(['--http.port=9000'], PinnedPortConfig::class);

        self::assertSame(8080, $settings->getPort());
        self::assertSame(
            ['warning: --http.port=9000 is overridden by ' . PinnedPortConfig::class . ': binding to 8080.'],
            $log,
        );
    }

    /** A configurer that keeps what it was handed lets the flag stand. */
    public function test_a_configurer_that_falls_back_to_what_it_was_given_keeps_the_flag(): void
    {
        [$settings, $log] = self::serverSettings(['--http.port=9000'], FallbackPortConfig::class);

        self::assertSame(9000, $settings->getPort());
        self::assertSame([], $log);
    }

    public function test_a_configurer_port_out_of_range_is_refused(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('The HTTP port must be 1–65535, 0 given.');
        self::serverSettings([], ZeroPortConfig::class);
    }
}

final class PinnedPortConfig extends WebConfigurerAdapter
{
    public function configureServer(ServerSettings $server, ApplicationArguments $args): void
    {
        $server->port(8080);
    }
}

final class FallbackPortConfig extends WebConfigurerAdapter
{
    public function configureServer(ServerSettings $server, ApplicationArguments $args): void
    {
        $server->port((int) env('LISTEN_FLAGS_TEST_PORT', $server->getPort()));
    }
}

final class ZeroPortConfig extends WebConfigurerAdapter
{
    public function configureServer(ServerSettings $server, ApplicationArguments $args): void
    {
        $server->port(0);
    }
}
