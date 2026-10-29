<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Supervision\ComponentRecords;
use Flytachi\Winter\Kernel\App\Supervision\Supervisor;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;

/**
 * A stop that arrives while the components are being started. The supervisor started
 * all of them in one pass and checked for a stop only before it, so the ones forked
 * after the signal never heard of it — and the supervisor waited for them for good
 * (`docker stop` in the first seconds of a container ended in SIGKILL).
 */
#[Group('integration')]
final class SupervisorStopRaceTest extends IntegrationCase
{
    public function test_a_stop_during_the_starts_starts_nothing_more_and_stops_what_runs(): void
    {
        $storage = $this->storage;
        $supervisor = $this->fork(static function () use ($storage): void {
            $write = static fn(string $line) => file_put_contents("{$storage}/log", $line . "\n", FILE_APPEND);
            // The second component asks whether an outsider holds it — the moment
            // between two starts — and the stop arrives right then.
            $records = new class implements ComponentRecords {
                private int $asked = 0;

                public function failed(Component $component): void
                {
                }

                public function stopped(Component $component): void
                {
                }

                public function forget(Component $component): void
                {
                }

                public function outsider(Component $component): ?int
                {
                    if (++$this->asked === 2) {
                        posix_kill(getmypid(), SIGTERM);
                    }
                    return null;
                }
            };
            $logger = new class ($write) extends AbstractLogger {
                public function __construct(private readonly \Closure $write)
                {
                }

                public function log($level, \Stringable|string $message, array $context = []): void
                {
                    ($this->write)("{$level} {$message}");
                }
            };
            $code = (new Supervisor(
                [
                    Component::command(['sleep', '20'], 'first', grace: 1.0),
                    Component::command(['sleep', '20'], 'second', grace: 1.0),
                    Component::command(['sleep', '20'], 'third', grace: 1.0),
                ],
                launch: static fn(Component $c) => null,
                report: static fn(string $what, \Throwable $e) => null,
                announce: static fn(string $message) => $write($message),
                records: $records,
                name: static fn(Component $c): string => $c->name(),
                logger: $logger,
            ))->run(untilDone: true);
            file_put_contents("{$storage}/exit", (string) $code);
        });

        $started = microtime(true);
        self::assertTrue($this->waitExit($supervisor, 8.0), 'the supervisor did not stop');
        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertSame('0', (string) @file_get_contents($this->storage . '/exit'));
        $log = (string) file_get_contents($this->storage . '/log');
        self::assertStringContainsString('Application stopping (SIGTERM)', $log);
        self::assertStringNotContainsString('third', $log, 'nothing is started after the stop');
    }
}
