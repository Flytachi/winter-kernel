<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/**
 * Integration fixture: a bare process that records every signal hook to the file
 * named by the WK_MARKER env var, so a parent test can assert signal reactions.
 */
class SignalProcess extends Process
{
    protected float $grace = 2.0;

    private function mark(string $event): void
    {
        $path = getenv('WK_MARKER');
        if ($path !== false && $path !== '') {
            file_put_contents($path, $event . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    public function run(): void
    {
        $this->mark('start');
        while ($this->isRunning()) {
            $this->sleep(0.1);
        }
    }

    protected function onTerminate(): void
    {
        $this->mark('terminate');
    }

    protected function onInterrupt(): void
    {
        $this->mark('interrupt');
    }

    protected function onReload(): void
    {
        $this->mark('reload');
    }

    protected function onUser1(): void
    {
        $this->mark('user1');
    }

    protected function onUser2(): void
    {
        $this->mark('user2');
    }
}
