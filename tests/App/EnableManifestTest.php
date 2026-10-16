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
use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler;
use Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures\EchoSocket;
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
#[EnableWebSocket(EchoSocket::class, port: 8002, host: '127.0.0.1')]
final class SocketApp extends WinterApplication
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
