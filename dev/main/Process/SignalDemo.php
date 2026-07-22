<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\K2\Dev\Process\Process;

/**
 * Proves per-signal hook dispatch: each of the 3 signals invokes its own
 * overridable handler. onClose (SIGHUP) deliberately does NOT stop — a reload.
 */
class SignalDemo extends Process
{
    public function run(): void
    {
        $this->logger->info('SignalDemo START pid=' . $this->pid);
        while ($this->running()) {
            $this->sleep(0.5);
        }
        $this->logger->info('SignalDemo loop exit (running=false)');
    }

    protected function onTerminate(): void
    {
        $this->logger->warning('HOOK onTerminate (SIGTERM) -> stopping');
        $this->requestStop();
    }

    protected function onInterrupt(): void
    {
        $this->logger->warning('HOOK onInterrupt (SIGINT) -> stopping');
        $this->requestStop();
    }

    protected function onClose(): void
    {
        // Reload semantics: react but keep running (no requestStop()).
        $this->logger->warning('HOOK onClose (SIGHUP) -> reload, NOT stopping');
    }
}
