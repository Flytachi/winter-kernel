<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Schedule;

use Flytachi\Winter\Kernel\Schedule\Trigger\Trigger;

/**
 * One discovered {@see Scheduled} method and its live scheduling state.
 *
 * The identity ({@see $className}, {@see $methodName}, {@see $trigger},
 * {@see $initialDelay}) is fixed at discovery; the rest is mutated by the
 * {@see Scheduler} loop as the task fires. The declaring class is resolved from
 * the container on each fire, so the task holds only its name.
 */
final class ScheduledTask
{
    /** Next fire time in wall-clock seconds; seeded from the trigger at boot. */
    public float $nextFireAt = 0.0;
    /** A run is in flight (dispatched, not yet finished); the task never overlaps itself. */
    public bool $inFlight = false;
    /** When the last run started (wall-clock seconds), or null before the first. */
    public ?float $lastStartAt = null;
    /** When the last run finished (wall-clock seconds), or null before the first. */
    public ?float $lastEndAt = null;
    /** How many times this task has run. */
    public int $runs = 0;

    /**
     * @param class-string $className Declaring class, resolved from the container on each fire.
     * @param string $methodName Public, zero-argument instance method to invoke.
     * @param Trigger $trigger Computes the next fire time from the last run.
     * @param float $initialDelay Seconds to wait before the first run.
     */
    public function __construct(
        public readonly string $className,
        public readonly string $methodName,
        public readonly Trigger $trigger,
        public readonly float $initialDelay = 0.0,
    ) {
    }

    /**
     * Stable identifier `Class::method`, for logs and the CLI listing.
     */
    public function id(): string
    {
        return $this->className . '::' . $this->methodName;
    }
}
