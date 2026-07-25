<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Schedule\Trigger;

/**
 * Fires at a fixed rate — the next start is measured from the previous START, so
 * the cadence is independent of how long a run takes.
 *
 * A run never overlaps itself: the scheduler holds the next fire until the current
 * run finishes. If a run outlasts the period, the next fire is already due when it
 * frees, so it runs once immediately — the missed ticks are dropped rather than
 * replayed as a burst.
 */
final readonly class FixedRateTrigger implements Trigger
{
    /**
     * @param float $rate Seconds between successive starts.
     */
    public function __construct(private float $rate)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function firstFireTime(float $now, float $initialDelay): float
    {
        return $now + $initialDelay;
    }

    /**
     * {@inheritDoc}
     */
    public function nextFireTime(float $now, ?float $lastStartAt, ?float $lastEndAt): float
    {
        // From the last start plus the rate; but never in the past — a run that
        // overran its period fires once now, not once per missed tick.
        return max($now, ($lastStartAt ?? $now) + $this->rate);
    }

    /**
     * {@inheritDoc}
     */
    public function describe(): string
    {
        return 'fixedRate ' . $this->rate . 's';
    }
}
