<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Daemon;

/**
 * Lifecycle state of one worker slot in a {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon}'s fleet.
 *
 * The slot number is stable (a restart reuses the same slot), and the state is
 * the reconcile loop's marker of intent — it lets the supervisor tell a worker
 * it retired on purpose (scale-down / stop) from one that died on its own, so
 * the restart policy and the autoscaler never fight.
 *
 * ```
 *  EMPTY ─fork─► STARTING ─heartbeat─► RUNNING ─exit─► RESTARTING ─backoff─► (fork)
 *                              │           │  ▲                       │
 *                       retire │    retire │  │ un-retire             │ give up
 *                              ▼           ▼  │                       ▼
 *                          RETIRING ◄───────┘ │                     EMPTY
 *                              │ deadline/force
 *                              ▼
 *                          KILLING ─reaped─► EMPTY
 * ```
 *
 * @link https://winterframe.net/docs/daemons Worker states in the fleet table
 */
enum SlotState: string
{
    /** Free slot, allocatable. */
    case EMPTY = 'empty';
    /** Forked, awaiting the worker's first heartbeat. */
    case STARTING = 'starting';
    /** Live and working (activity is IDLE/BUSY separately). */
    case RUNNING = 'running';
    /** Draining after an intentional SIGTERM (scale-down / stop); will not be refilled. */
    case RETIRING = 'retiring';
    /** SIGKILL sent after the retire deadline; awaiting reap. */
    case KILLING = 'killing';
    /** Unexpected death; in back-off before a re-fork into the same slot. */
    case RESTARTING = 'restarting';
    /** Died and the restart policy declined to replace it (NEVER, or a clean exit). Terminal. */
    case RETIRED = 'retired';

    /**
     * Counts toward the live fleet size the autoscaler drives to desired.
     *
     * Includes a worker that is or will be running, and a {@see RETIRED} slot:
     * a death the policy declined to replace occupies its slot terminally, so
     * reconcile does not refill it (which would bypass back-off and the policy).
     */
    public function isCommitted(): bool
    {
        return $this === self::STARTING
            || $this === self::RUNNING
            || $this === self::RESTARTING
            || $this === self::RETIRED;
    }

    /**
     * Has a live OS process attached.
     */
    public function isAlive(): bool
    {
        return $this === self::STARTING
            || $this === self::RUNNING
            || $this === self::RETIRING
            || $this === self::KILLING;
    }
}
