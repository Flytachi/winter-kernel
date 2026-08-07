<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Internal;

use Flytachi\Winter\Kernel\Kernel;

/**
 * The per-class singleton guard shared by {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process}
 * and {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon}.
 *
 * A crash-safe advisory `flock` — held for the process lifetime and released
 * automatically by the OS on death, so it never goes stale like a PID file. It is
 * a **trait with private members** on purpose: both framework classes reuse it,
 * but it stays invisible to application subclasses. Acquiring or releasing it by
 * hand would break the "one instance per class" contract, so it is not part of
 * the developer-facing surface.
 */
trait SingletonLock
{
    /** @var resource|null Held for the process lifetime; the flock is the singleton guard. */
    private $lockHandle = null;

    /**
     * Takes the per-class lock. Returns false when another instance already holds
     * it; true when acquired — or when the lock file cannot be created, in which
     * case it proceeds best-effort rather than block on a filesystem issue.
     */
    private function acquireLock(): bool
    {
        $handle = @fopen($this->lockPath(), 'c');
        if ($handle === false) {
            return true;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->lockHandle = $handle;
        return true;
    }

    /**
     * Releases the lock, if held. The OS also releases it when the process dies.
     */
    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Path of this class's lock file, under the runnable storage directory.
     */
    private function lockPath(): string
    {
        return Kernel::$pathStorageRunnable . '/' . str_replace('\\', '.', static::class) . '.lock';
    }
}
