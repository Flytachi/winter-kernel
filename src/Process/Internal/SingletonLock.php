<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Internal;

use Flytachi\Winter\Kernel\Kernel;

/**
 * The per-class singleton guard shared by {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process}
 * and {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon}.
 *
 * A {@see LockFile} held for the process lifetime and released automatically by the OS
 * on death, so it never goes stale like a PID file. It is a **trait with private
 * members** on purpose: both framework classes reuse it, but it stays invisible to
 * application subclasses. Acquiring or releasing it by hand would break the "one
 * instance per class" contract, so it is not part of the developer-facing surface.
 *
 * The lock, not the status record, is what says an instance is alive: the record
 * survives a SIGKILL, and its PID may belong to someone else after a restart — in a
 * container, often to the very process that is starting. See {@see probeLock()}. Only
 * where `flock` works, though: the holder checks that on taking it, and the record
 * carries the answer ({@see \Flytachi\Winter\Kernel\Process\ProcessStatus::$locked}).
 */
trait SingletonLock
{
    /** Held for the process lifetime; the lock is the singleton guard. */
    private ?LockFile $lock = null;

    /**
     * Whether the held lock really excludes others — not so on every filesystem.
     * Written into the status record, so {@see probeLock()} is trusted only where it
     * means something.
     */
    private bool $lockReliable = false;

    /**
     * Takes the per-class lock. Returns false when another instance already holds
     * it; true when acquired — or when the lock file cannot be created, in which
     * case it proceeds best-effort rather than block on a filesystem issue.
     */
    private function acquireLock(): bool
    {
        $lock = LockFile::take(self::lockPath());
        if ($lock === null) {
            return false;
        }
        $this->lock = $lock;
        $this->lockReliable = $lock->reliable;
        return true;
    }

    /**
     * Releases the lock, if held. The OS also releases it when the process dies.
     */
    private function releaseLock(): void
    {
        $this->lock?->release();
        $this->lock = null;
        $this->lockReliable = false;
    }

    /**
     * Whether a live instance holds the lock — see {@see LockFile::probe()}.
     *
     * @return bool|null True held, false free, null when the lock file cannot be read.
     */
    private static function probeLock(): ?bool
    {
        return LockFile::probe(self::lockPath());
    }

    /**
     * Path of this class's lock file, under the runnable storage directory.
     */
    private static function lockPath(): string
    {
        return Kernel::$pathRunnable . '/' . str_replace('\\', '.', static::class) . '.lock';
    }
}
