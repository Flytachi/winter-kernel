<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRuntime;
use Flytachi\Winter\Kernel\App\Supervision\ComponentControl;
use Flytachi\Winter\Kernel\App\Supervision\ComponentRecords;
use Flytachi\Winter\Kernel\App\Supervision\Supervisor;
use Flytachi\Winter\Kernel\WinterApplication;
use Psr\Log\AbstractLogger;

/**
 * Runs the application's supervisor in a forked child, the way `call run` without a web
 * tier does, and reads what it said: its exit code, its log, its loud lines.
 *
 * With `$asApplication` the child also claims a run of a test application, and this
 * process takes that application for its own — as the console or code inside the
 * application does — so components are started, stopped and restarted through it.
 *
 * @mixin IntegrationCase
 */
trait SupervisesComponents
{
    private static string $testApp = 'Tests\\SupervisedApp';

    /** Takes the test application for this process's own, as a booted kernel does. */
    private function actAsApplication(?string $appClass): void
    {
        (new \ReflectionProperty(WinterApplication::class, 'appClass'))->setValue(null, $appClass ?? '');
    }

    /**
     * @param list<Component> $components
     */
    private function supervise(array $components, bool $asApplication = false): int
    {
        $storage = $this->storage;
        $app = self::$testApp;
        if ($asApplication) {
            $this->actAsApplication($app);
        }
        return $this->fork(static function () use ($components, $storage, $asApplication, $app): void {
            $write = static fn(string $file, string $line) =>
                file_put_contents("{$storage}/{$file}", $line . "\n", FILE_APPEND);
            $logger = new class ($write) extends AbstractLogger {
                public function __construct(private readonly \Closure $write)
                {
                }

                public function log($level, \Stringable|string $message, array $context = []): void
                {
                    ($this->write)('log', "{$level} {$message}");
                }
            };
            $run = $asApplication ? ApplicationRuntime::claim($app, $components)->managedBy() : null;
            $code = (new Supervisor(
                $components,
                launch: static function (Component $c): void {
                    ApplicationRuntime::markManaged((string) $c->class);
                    ((string) $c->class)::startWith($c->args);
                },
                report: static fn(string $what, \Throwable $e) =>
                    $write('reports', "{$what} — " . $e::class . ": {$e->getMessage()}"),
                announce: static fn(string $message) => $write('announced', $message),
                records: new class implements ComponentRecords {
                    public function failed(Component $component): void
                    {
                        $component->class !== null && $component->class::recordFailed();
                    }

                    public function stopped(Component $component): void
                    {
                        $component->class !== null && $component->class::recordStopped();
                    }

                    public function forget(Component $component): void
                    {
                        $component->class !== null && $component->class::forgetRecord();
                    }

                    public function outsider(Component $component): ?int
                    {
                        return $component->class !== null ? $component->class::outsider() : null;
                    }
                },
                name: static fn(Component $c): string => $c->name(),
                logger: $logger,
                commands: $run === null ? null : static fn(): array => ComponentControl::pending($run),
                publish: $run === null
                    ? null
                    : static fn(array $units) => ApplicationRuntime::publishUnits($app, $units),
            ))->run(untilDone: !$asApplication);
            file_put_contents("{$storage}/exit", (string) $code);
        });
    }

    private function exitCode(int $supervisor, float $timeout = 10.0): ?int
    {
        $file = $this->storage . '/exit';
        $this->pollUntil(static fn() => is_file($file), $timeout);
        $this->waitExit($supervisor);
        return is_file($file) ? (int) file_get_contents($file) : null;
    }

    private function announced(): string
    {
        return (string) @file_get_contents($this->storage . '/announced');
    }

    private function reports(): string
    {
        return (string) @file_get_contents($this->storage . '/reports');
    }

    private function logged(): string
    {
        return (string) @file_get_contents($this->storage . '/log');
    }

    private function waitLogged(string $needle, float $timeout = 8.0): bool
    {
        return $this->pollUntil(fn() => str_contains($this->logged(), $needle), $timeout);
    }
}
