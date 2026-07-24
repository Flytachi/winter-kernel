<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Daemon;

use Flytachi\Winter\K2\Process\Activity;

/**
 * One worker slot in a {@see Daemon}'s fleet.
 *
 * Mutable — the {@see SupervisesFleet} loop advances its {@see SlotState} machine in place.
 * The slot {@see $index} is stable: a restart reuses the same slot, so a worker's
 * `worker#{n}` label never changes under a running daemon.
 */
final class Slot
{
    /** Current lifecycle state. */
    public SlotState $state = SlotState::EMPTY;
    /** Live worker PID (0 when none). */
    public int $pid = 0;
    /** Absolute microtime deadline for a RETIRING worker before SIGKILL; INF = wait forever. */
    public float $deadline = INF;
    /** Microtime at which a RESTARTING slot may re-fork (end of back-off). */
    public float $restartAt = 0.0;
    /** How many times this slot's worker has been restarted. */
    public int $restarts = 0;
    /** When the current worker was forked (unix seconds; 0 when none). */
    public int $startedAt = 0;
    /** Last activity reported by the worker's heartbeat; best-effort. */
    public Activity $activity = Activity::IDLE;
    /** Unix seconds of the worker's last heartbeat (0 = none yet); drives the liveness watchdog. */
    public int $heartbeatAt = 0;
    /** The watchdog SIGKILLed this hung worker; awaiting reap (avoids a repeat kill). */
    public bool $killed = false;

    /**
     * @param int $index The stable slot number, kept across restarts.
     */
    public function __construct(public readonly int $index)
    {
    }
}
