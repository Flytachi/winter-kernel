<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Priority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an outside program may be told: how it is known to be ready, which signal stops
 * it, how often it is restarted, and when it starts relative to the rest.
 */
final class ComponentReadyTest extends TestCase
{
    public function test_an_outside_program_keeps_its_old_defaults(): void
    {
        $program = Component::command(['node', 'bridge.js']);

        self::assertNull($program->ready);
        self::assertSame(0, $program->startTimeout, 'ready the moment it runs: nothing to time');
        self::assertSame(SIGTERM, $program->stopSignal);
        self::assertSame(0, $program->retries);
        self::assertSame(Priority::Normal, $program->priority);
    }

    public function test_a_ready_check_gets_a_start_timeout(): void
    {
        self::assertSame(60, Component::command(['pg'], ready: ['pg_isready'])->startTimeout);
        self::assertSame(5, Component::command(['pg'], ready: 'tcp://127.0.0.1:5432', startTimeout: 5)->startTimeout);
        self::assertSame('tcp://[::1]:6379', Component::command(['r'], ready: 'tcp://[::1]:6379')->ready);
    }

    public function test_every_component_takes_a_priority_and_keeps_it_when_moved(): void
    {
        self::assertSame(Priority::Low, Component::http(priority: Priority::Low)->at('127.0.0.1', 9000)->priority);
        self::assertSame(Priority::High, Component::process(\stdClass::class, priority: Priority::High)->priority);
    }

    /** @return iterable<string, array{list<string>|string}> */
    public static function badChecks(): iterable
    {
        yield 'no scheme' => ['127.0.0.1:5432'];
        yield 'no port' => ['tcp://127.0.0.1'];
        yield 'port out of range' => ['tcp://127.0.0.1:70000'];
        yield 'no host' => ['tcp://:5432'];
        yield 'empty command' => [[]];
        yield 'empty program' => [['']];
    }

    /** @param list<string>|string $ready */
    #[DataProvider('badChecks')]
    public function test_a_bad_ready_check_is_refused(array|string $ready): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Component::command(['pg'], ready: $ready);
    }

    public function test_a_stop_signal_must_be_a_signal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Component::command(['pg'], stopSignal: 0);
    }

    public function test_priorities_start_high_first(): void
    {
        self::assertSame([Priority::High, Priority::Normal, Priority::Low], Priority::inStartOrder());
        self::assertSame([0, 1, 2], array_map(static fn(Priority $p): int => $p->rank(), Priority::inStartOrder()));
    }
}
