<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\SignalProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\TickingDaemon;
use Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures\EchoSocket;
use PHPUnit\Framework\TestCase;

/**
 * A process the application was not declared with, taken in while it runs: its kind
 * follows from its class, and it is never restarted and never critical.
 */
final class AdoptedComponentTest extends TestCase
{
    public function test_its_kind_follows_from_its_class(): void
    {
        self::assertSame(ComponentKind::Process, Component::adopted(SignalProcess::class)->kind);
        self::assertSame(ComponentKind::Daemon, Component::adopted(TickingDaemon::class)->kind);
        self::assertSame(ComponentKind::Scheduler, Component::adopted(Scheduler::class)->kind);
        self::assertSame(ComponentKind::WebSocket, Component::adopted(EchoSocket::class, ['port' => '8001'])->kind);
    }

    public function test_it_is_never_restarted_and_never_critical(): void
    {
        $component = Component::adopted(SignalProcess::class, ['a' => 'b']);

        self::assertTrue($component->adopted);
        self::assertFalse($component->critical);
        self::assertSame(0, $component->retries);
        self::assertSame(['a' => 'b'], $component->args);
    }

    public function test_a_websocket_takes_its_port_and_host_from_what_it_was_started_with(): void
    {
        $socket = Component::adopted(EchoSocket::class, ['port' => '8002', 'host' => '127.0.0.1']);

        self::assertSame([8002, '127.0.0.1'], [$socket->port, $socket->host]);
    }

    public function test_a_websocket_without_a_port_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('it needs a port');
        Component::adopted(EchoSocket::class);
    }

    public function test_a_class_that_is_not_a_process_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a Process class');
        Component::adopted(\stdClass::class);
    }
}
