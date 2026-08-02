<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/**
 * Dispatched by {@see \Flytachi\Winter\Kernel\Tests\Process\Integration\DispatchRunnerTest}
 * into a detached process. It writes its PID to a marker file — the only evidence the
 * test process can observe, since the child is a separate PHP process with its own
 * kernel — and then idles until stopped.
 */
final class DispatchMarkerProcess extends Process
{
    /** Fixed path so the test and the detached child agree without sharing state. */
    public static function markerPath(): string
    {
        return sys_get_temp_dir() . '/wk_dispatch_marker';
    }

    public function run(): void
    {
        file_put_contents(self::markerPath(), (string) getmypid());

        while ($this->isRunning()) {
            $this->sleep(0.2);
        }
    }
}
