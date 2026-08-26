<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/**
 * Fixture for the inherited-shutdown guard: dispatches a few tasks and records every
 * `onShutdown()` call, with the PID that made it, into the file named by `WK_MARKER`.
 *
 * Without Swoole each `spawn()` runs its task in a forked child that exits when the task
 * is done. Those children inherit the shutdown callbacks registered before the fork, so
 * an unguarded backstop would run the process's `onShutdown()` once per dispatched task,
 * in the wrong process. The marker file makes that visible: one line is correct.
 */
final class SpawnShutdownProcess extends Process
{
    private const int TASKS = 3;

    public function run(): void
    {
        for ($i = 0; $i < self::TASKS; $i++) {
            $this->spawn(static fn(): null => null);
        }
    }

    protected function onShutdown(): void
    {
        $marker = getenv('WK_MARKER');
        if (is_string($marker) && $marker !== '') {
            file_put_contents($marker, getmypid() . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}
