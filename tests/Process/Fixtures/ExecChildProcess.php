<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/**
 * Integration fixture: a process that execs a long-lived program and leaves it
 * running — the way a process runs `ffmpeg` or dispatches another one. The program's
 * PID goes to the file named by WK_MARKER, so a test can check that the program, which
 * inherits this process's descriptors, does not keep its singleton lock once the
 * process is dead.
 */
class ExecChildProcess extends Process
{
    public function run(): void
    {
        $program = proc_open(['sleep', '30'], [], $pipes);
        $pid = is_resource($program) ? (int) proc_get_status($program)['pid'] : 0;
        $path = getenv('WK_MARKER');
        if ($path !== false && $path !== '') {
            file_put_contents($path, $pid . "\n", FILE_APPEND | LOCK_EX);
        }
        while ($this->isRunning()) {
            $this->sleep(0.1);
        }
    }
}
