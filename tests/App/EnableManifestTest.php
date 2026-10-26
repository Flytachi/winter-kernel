<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Attribute\EnableAsync;
use Flytachi\Winter\Kernel\App\Attribute\EnableDaemon;
use Flytachi\Winter\Kernel\App\Attribute\EnableProcess;
use Flytachi\Winter\Kernel\App\Attribute\EnableScheduler;
use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\App\Attribute\EnableWeb;
use Flytachi\Winter\Kernel\App\Attribute\EnableWebSocket;
use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Components;
use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler;
use Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures\EchoSocket;
use Flytachi\Winter\Kernel\WebSocket\Connection;
use Flytachi\Winter\Kernel\WebSocket\Frame;
use Flytachi\Winter\Kernel\WebSocket\WebSocket;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The #[Enable*] → Component manifest resolution, tested as a pure decision
 * algorithm via reflection on fixture App classes (no boot, no forks).
 */
final class EnableManifestTest extends TestCase
{
    /**
     * @param class-string<WinterApplication> $app
     * @return list<Component>
     */
    private function resolve(string $app): array
    {
        return new ReflectionMethod($app, 'resolveComponents')->invoke(null);
    }

    /**
     * @param class-string<WinterApplication> $app
     * @param class-string $attribute
     */
    private function hasAttr(string $app, string $attribute): bool
    {
        return new ReflectionMethod($app, 'hasAttribute')->invoke(null, $attribute);
    }

    public function test_full_manifest_maps_every_attribute_in_order(): void
    {
        $c = $this->resolve(FullApp::class);

        self::assertCount(5, $c);
        self::assertSame(ComponentKind::Http, $c[0]->kind);
        self::assertSame(ComponentKind::Scheduler, $c[1]->kind);
        self::assertSame(Scheduler::class, $c[1]->class);
        self::assertSame(ComponentKind::Process, $c[2]->kind);
        self::assertSame(ComponentKind::Process, $c[3]->kind);
        self::assertSame(ComponentKind::Daemon, $c[4]->kind);
    }

    public function test_repeatable_process_preserves_declaration_order(): void
    {
        $c = $this->resolve(FullApp::class);

        self::assertSame('Main\\Proc\\A', $c[2]->class);
        self::assertSame('Main\\Proc\\B', $c[3]->class);
        self::assertSame('Main\\Daemon\\E', $c[4]->class);
    }

    public function test_scheduler_uses_declared_class(): void
    {
        $c = $this->resolve(CustomSchedulerApp::class);

        self::assertCount(1, $c);
        self::assertSame(ComponentKind::Scheduler, $c[0]->kind);
        self::assertSame('Custom\\Sched', $c[0]->class);
    }

    public function test_headless_app_has_no_http(): void
    {
        $c = $this->resolve(HeadlessApp::class);

        self::assertCount(1, $c);
        self::assertSame(ComponentKind::Process, $c[0]->kind);
        self::assertSame('Main\\Proc\\Only', $c[0]->class);
    }

    public function test_empty_app_yields_empty_manifest(): void
    {
        self::assertSame([], $this->resolve(EmptyApp::class));
    }

    public function test_enable_async_is_detected(): void
    {
        self::assertTrue($this->hasAttr(FullApp::class, EnableAsync::class));
        self::assertFalse($this->hasAttr(HeadlessApp::class, EnableAsync::class));
    }

    public function test_websocket_carries_its_port_and_host(): void
    {
        $c = $this->resolve(SocketApp::class);

        self::assertCount(3, $c);
        self::assertSame(ComponentKind::WebSocket, $c[1]->kind);
        self::assertSame(EchoSocket::class, $c[1]->class);
        self::assertSame(8001, $c[1]->port);
        self::assertSame('0.0.0.0', $c[1]->host);
        self::assertSame(8002, $c[2]->port);
        self::assertSame('127.0.0.1', $c[2]->host);
    }

    public function test_one_class_declared_twice_is_refused(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('EchoSocket is declared twice');
        $this->resolve(SameSocketTwiceApp::class);
    }

    public function test_websocket_under_enable_process_is_refused(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('#[EnableWebSocket(EchoSocket::class, port: …)]');
        $this->resolve(SocketAsProcessApp::class);
    }

    public function test_enable_websocket_with_a_plain_class_is_refused(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('expects a WebSocket class');
        $this->resolve(NotASocketApp::class);
    }

    public function test_a_websocket_on_the_web_port_is_refused(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('Port 8000 is used twice: by the web server and by EchoSocket');
        new ReflectionMethod(WinterApplication::class, 'assertFreePorts')
            ->invoke(null, [Component::websocket(EchoSocket::class, 8000)], 8000);
    }

    public function test_two_websockets_on_one_port_are_refused(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('Port 8001 is used twice');
        new ReflectionMethod(WinterApplication::class, 'assertFreePorts')->invoke(null, [
            Component::websocket(EchoSocket::class, 8001),
            Component::websocket(EchoSocket::class, 8001),
        ], null);
    }

    public function test_distinct_ports_pass(): void
    {
        new ReflectionMethod(WinterApplication::class, 'assertFreePorts')->invoke(null, [
            Component::process('Main\\Proc\\A'),
            Component::websocket(EchoSocket::class, 8001),
            Component::websocket(EchoSocket::class, 8002),
        ], 8000);
        $this->addToAssertionCount(1);
    }

    // ── Supervision parameters ───────────────────────────────────────────────

    public function test_components_default_to_critical_with_three_retries_and_a_minute_to_start(): void
    {
        $c = $this->resolve(FullApp::class);

        foreach (array_slice($c, 1) as $component) {
            self::assertTrue($component->critical, $component->kind->name);
            self::assertSame(60, $component->startTimeout, $component->kind->name);
        }
        self::assertSame([3, 3, 3, 0], array_map(static fn($c) => $c->retries, array_slice($c, 1)), 'a daemon: none');
    }

    public function test_declared_supervision_parameters_reach_the_components(): void
    {
        [$scheduler, $process, $socket, $daemon] = $this->resolve(TunedApp::class);

        self::assertSame([false, 1, 0], [$scheduler->critical, $scheduler->retries, $scheduler->startTimeout]);
        self::assertSame([false, 5, 120], [$process->critical, $process->retries, $process->startTimeout]);
        self::assertSame([false, 2, 10], [$socket->critical, $socket->retries, $socket->startTimeout]);
        self::assertSame([8001, '127.0.0.1'], [$socket->port, $socket->host]);
        self::assertSame([false, 0, 300], [$daemon->critical, $daemon->retries, $daemon->startTimeout]);
    }

    public function test_a_negative_value_is_a_boot_error_naming_its_declaration(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('#[EnableProcess(Only)]: retries and startTimeout cannot be negative.');
        $this->resolve(NegativeRetriesApp::class);
    }

    // ── Components declared in code ──────────────────────────────────────────

    public function test_components_declared_in_code_add_to_the_attributes(): void
    {
        $c = $this->resolve(CodeDeclaredApp::class);

        self::assertSame(
            [
                ComponentKind::Http,
                ComponentKind::Process,
                ComponentKind::Daemon,
                ComponentKind::Command,
                ComponentKind::Command,
            ],
            array_map(static fn(Component $c) => $c->kind, $c),
        );
        self::assertSame('Main\\Daemon\\E', $c[2]->class);
        self::assertFalse($c[2]->critical);
        self::assertSame(['node', 'tools/bridge.js'], $c[3]->command);
        self::assertSame('bridge', $c[3]->name());
        self::assertSame([PHP_BINARY, __FILE__, '--once'], $c[4]->command);
        self::assertSame('EnableManifestTest', $c[4]->name(), 'a script is named after its file');
        self::assertSame([0, 0], [$c[3]->retries, $c[3]->startTimeout], 'a program is never restarted');
    }

    public function test_a_class_declared_by_an_attribute_and_in_code_is_refused(): void
    {
        $this->expectExceptionMessage('A is declared twice');
        $this->resolve(DeclaredTwiceApp::class);
    }

    public function test_two_programs_with_one_name_are_refused(): void
    {
        $this->expectExceptionMessage('The program "bridge" is declared twice');
        $this->resolve(SameProgramTwiceApp::class);
    }

    public function test_a_bad_value_in_code_names_the_hook(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('CodeNegativeApp::components(): retries and startTimeout cannot be negative.');
        $this->resolve(CodeNegativeApp::class);
    }

    public function test_a_missing_script_stops_the_boot(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('Script scripts/nowhere.php not found');
        $this->resolve(MissingScriptApp::class);
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

#[EnableWeb]
#[EnableAsync]
#[EnableScheduler]
#[EnableProcess('Main\\Proc\\A')]
#[EnableProcess('Main\\Proc\\B')]
#[EnableDaemon('Main\\Daemon\\E')]
final class FullApp extends WinterApplication
{
}

#[EnableScheduler('Custom\\Sched')]
final class CustomSchedulerApp extends WinterApplication
{
}

#[EnableProcess('Main\\Proc\\Only')]
final class HeadlessApp extends WinterApplication
{
}

final class EmptyApp extends WinterApplication
{
}

#[EnableWeb]
#[EnableWebSocket(EchoSocket::class, port: 8001)]
#[EnableWebSocket(SecondSocket::class, port: 8002, host: '127.0.0.1')]
final class SocketApp extends WinterApplication
{
}

/** A second socket class: one class runs once, so two sockets are two classes. */
final class SecondSocket extends WebSocket
{
    protected function onMessage(Connection $conn, Frame $frame): void
    {
    }
}

#[EnableWebSocket(EchoSocket::class, port: 8001)]
#[EnableWebSocket(EchoSocket::class, port: 8002)]
final class SameSocketTwiceApp extends WinterApplication
{
}

#[EnableProcess(EchoSocket::class)]
final class SocketAsProcessApp extends WinterApplication
{
}

#[EnableWebSocket(\stdClass::class, port: 8001)]
final class NotASocketApp extends WinterApplication
{
}

#[EnableScheduler(critical: false, retries: 1, startTimeout: 0)]
#[EnableProcess('Main\\Proc\\A', critical: false, retries: 5, startTimeout: 120)]
#[EnableWebSocket(EchoSocket::class, port: 8001, host: '127.0.0.1', critical: false, retries: 2, startTimeout: 10)]
#[EnableDaemon('Main\\Daemon\\E', critical: false, startTimeout: 300)]
final class TunedApp extends WinterApplication
{
}

#[EnableProcess('Main\\Proc\\Only', retries: -1)]
final class NegativeRetriesApp extends WinterApplication
{
}

#[EnableWeb]
#[EnableProcess('Main\\Proc\\A')]
final class CodeDeclaredApp extends WinterApplication
{
    protected static function components(Components $components): void
    {
        $components
            ->daemon('Main\\Daemon\\E', critical: false)
            ->command(['node', 'tools/bridge.js'], name: 'bridge')
            ->script(__FILE__, args: ['--once']);
    }
}

#[EnableProcess('Main\\Proc\\A')]
final class DeclaredTwiceApp extends WinterApplication
{
    protected static function components(Components $components): void
    {
        $components->process('Main\\Proc\\A');
    }
}

final class SameProgramTwiceApp extends WinterApplication
{
    protected static function components(Components $components): void
    {
        $components->command(['node', 'a.js'], name: 'bridge')->command(['node', 'b.js'], name: 'bridge');
    }
}

final class CodeNegativeApp extends WinterApplication
{
    protected static function components(Components $components): void
    {
        $components->process('Main\\Proc\\A', retries: -1);
    }
}

final class MissingScriptApp extends WinterApplication
{
    protected static function components(Components $components): void
    {
        $components->script('scripts/nowhere.php');
    }
}
