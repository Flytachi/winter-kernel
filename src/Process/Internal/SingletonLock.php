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
 *
 * The lock, not the status record, is what says an instance is alive: the record
 * survives a SIGKILL, and its PID may belong to someone else after a restart — in a
 * container, often to the very process that is starting. See {@see probeLock()}. Only
 * where `flock` works, though: the holder checks that on taking it, and the record
 * carries the answer ({@see \Flytachi\Winter\Kernel\Process\ProcessStatus::$locked}).
 *
 * The lock file itself is never deleted: a process holding the lock on a deleted file
 * and another locking a new file of the same name would both run.
 *
 * A forked child shares the parent's lock (one open file description), so a child must
 * never call {@see releaseLock()}: `LOCK_UN` there releases the parent's lock too.
 */
trait SingletonLock
{
    /** @var resource|null Held for the process lifetime; the flock is the singleton guard. */
    private $lockHandle = null;

    /**
     * Whether the held lock really excludes others — not so on every filesystem: a host
     * directory mounted into Docker Desktop grants every `flock`. Written into the
     * status record, so {@see probeLock()} is trusted only where it means something.
     */
    private bool $lockReliable = false;

    /**
     * Takes the per-class lock. Returns false when another instance already holds
     * it; true when acquired — or when the lock file cannot be created, in which
     * case it proceeds best-effort rather than block on a filesystem issue.
     *
     * Opened close-on-exec: a program this process execs (a dispatched process,
     * `exec('ffmpeg …')`) would otherwise inherit the descriptor and keep the lock
     * held after this process is dead, so it could not be started again.
     *
     * Tried a few times: a {@see probeLock()} holds a shared lock for a moment, and a
     * start that meets it must not take it for a running instance.
     */
    private function acquireLock(): bool
    {
        // Created apart from the descriptor that locks it: on a host directory mounted
        // into Docker Desktop, a lock taken through the very descriptor that created the
        // file excludes nobody, while one on an existing file does.
        $path = self::lockPath();
        if (!is_file($path)) {
            @touch($path);
        }
        $handle = @fopen($path, 'ce');
        if ($handle === false) {
            return true;
        }
        for ($attempt = 1; !flock($handle, LOCK_EX | LOCK_NB); $attempt++) {
            if ($attempt === 5) {
                fclose($handle);
                return false;
            }
            usleep(20_000);
        }
        $this->lockHandle = $handle;
        $this->lockReliable = self::probeLock() === true;
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
            $this->lockReliable = false;
        }
    }

    /**
     * Whether a live instance holds the lock — the liveness test that survives a crash.
     *
     * Read-only and with a shared lock, so any user able to read the file can ask: a
     * running instance holds it exclusively, and the shared lock is refused. Asked from
     * inside the running instance itself it is refused too — a second descriptor does
     * not share the first one's lock — so the answer is still "held".
     *
     * @return bool|null True held, false free, null when the lock file cannot be read
     *                   (never created, or no permission) — the caller decides then.
     */
    private static function probeLock(): ?bool
    {
        $handle = @fopen(self::lockPath(), 'r');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_SH | LOCK_NB)) {
                return true;
            }
            flock($handle, LOCK_UN);
            return false;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Path of this class's lock file, under the runnable storage directory.
     */
    private static function lockPath(): string
    {
        return Kernel::$pathStorageRunnable . '/' . str_replace('\\', '.', static::class) . '.lock';
    }
}
