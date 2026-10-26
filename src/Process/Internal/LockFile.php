<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Internal;

/**
 * A crash-safe advisory `flock` on a file — "one at a time" that the operating system
 * cleans up: the lock goes with the process that held it, however it died.
 *
 * What makes it usable as a liveness test, and not only as a guard:
 *
 * - **Close-on-exec.** A program the holder execs (a dispatched process, a script)
 *   would otherwise inherit the descriptor and keep the lock after the holder is dead.
 * - **The file is never deleted.** A holder of a deleted file and a holder of its
 *   replacement would both believe they are alone.
 * - **The file is created apart from the descriptor that locks it.** On a host
 *   directory mounted into Docker Desktop a lock taken through the very descriptor that
 *   created the file excludes nobody, while one on an existing file does.
 * - **The holder checks that it works** ({@see $reliable}): where `flock` does not
 *   exclude, a free lock proves nothing, and whoever asks must fall back to something
 *   else.
 *
 * A forked child shares its parent's lock (one open file description), so a child must
 * never {@see release()} it: `LOCK_UN` there releases the parent's lock too.
 *
 * @internal Shared by the process singleton guard and the application's own lock.
 */
final class LockFile
{
    /** Attempts to take a lock held for a moment by a {@see probe()}. */
    private const int ATTEMPTS = 5;
    /** Pause between attempts, in microseconds. */
    private const int RETRY_PAUSE = 20_000;

    /**
     * @param resource|null $handle Null when the file could not be opened (best effort).
     * @param bool $reliable Whether the lock excludes others on this filesystem.
     */
    private function __construct(
        private mixed $handle,
        public readonly bool $reliable,
    ) {
    }

    /**
     * Takes the lock. Null when another holder has it. When the file cannot be opened at
     * all, a lock that holds nothing is returned rather than blocking on a filesystem
     * issue — {@see $reliable} is false then.
     *
     * Tried a few times: a {@see probe()} holds a shared lock for a moment, and a taker
     * that meets it must not take it for a live holder.
     */
    public static function take(string $path): ?self
    {
        if (!is_file($path)) {
            @touch($path);
        }
        $handle = @fopen($path, 'ce');
        if ($handle === false) {
            return new self(null, false);
        }
        for ($attempt = 1; !flock($handle, LOCK_EX | LOCK_NB); $attempt++) {
            if ($attempt === self::ATTEMPTS) {
                fclose($handle);
                return null;
            }
            usleep(self::RETRY_PAUSE);
        }
        return new self($handle, self::probe($path) === true);
    }

    /**
     * Whether someone holds the lock — the liveness test that survives a crash.
     *
     * Read-only and with a shared lock, so any user able to read the file can ask: a
     * holder has it exclusively, and the shared lock is refused. Asked from inside the
     * holder itself it is refused too — a second descriptor does not share the first
     * one's lock — so the answer is still "held".
     *
     * @return bool|null True held, false free, null when the file cannot be read (never
     *                   created, or no permission) — the caller decides then.
     */
    public static function probe(string $path): ?bool
    {
        $handle = @fopen($path, 'r');
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

    /** Releases the lock. The OS also releases it when the holder dies. */
    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
