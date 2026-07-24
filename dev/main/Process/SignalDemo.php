<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\K2\Process\InterruptedException;
use Flytachi\Winter\K2\Process\Process;

/**
 * Reference of the signal contract with canonical PSR-3 log levels.
 *
 * The body sits in a long sleep to show interruptibility: one SIGTERM/SIGINT
 * wakes it instantly through InterruptedException — no waiting the sleep out.
 */
class SignalDemo extends Process
{
    private string $verbosity = 'info';

    public function run(): void
    {
        $this->logger->info('Process started');

        try {
            while ($this->isRunning()) {
                $this->logger->debug('Working');
                $this->sleep(30);
            }
        } catch (InterruptedException) {
            // Stop arrived mid-sleep — handle partial work (requeue, roll back) here.
            $this->logger->info('Interrupted during sleep, shutting down');
        } finally {
            $this->logger->debug('Releasing local resources');
        }

        $this->logger->info('Process stopped');
    }

    // --- stop signals: shutdown is guaranteed; the hook is only your reaction ---

    protected function onTerminate(): void
    {
        $this->logger->info('SIGTERM received, shutting down');
    }

    protected function onInterrupt(): void
    {
        $this->logger->info('SIGINT received, shutting down');
    }

    // --- control signals: the process keeps running ---

    protected function onReload(): void
    {
        $this->logger->notice('SIGHUP received, reloading configuration');
    }

    protected function onUser1(): void
    {
        $this->logger->notice('SIGUSR1 received, reopening log files');
    }

    protected function onUser2(): void
    {
        $this->verbosity = $this->verbosity === 'info' ? 'debug' : 'info';
        $this->logger->notice("SIGUSR2 received, verbosity set to {$this->verbosity}");
    }

    // --- guaranteed teardown on every exit path (graceful, forced, fatal) ---

    protected function onShutdown(): void
    {
        $this->logger->info('Shutdown hook: final teardown');
    }
}
