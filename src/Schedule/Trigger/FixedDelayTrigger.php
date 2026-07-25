<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Schedule\Trigger;

/**
 * Fires a fixed delay after the previous run finished — the next start is measured
 * from the last END, so a run's own duration never eats into the gap. Two runs of
 * the same task can therefore never overlap.
 */
final readonly class FixedDelayTrigger implements Trigger
{
    /**
     * @param float $delay Seconds to wait after a run finishes before the next.
     */
    public function __construct(private float $delay)
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
        return ($lastEndAt ?? $now) + $this->delay;
    }

    /**
     * {@inheritDoc}
     */
    public function describe(): string
    {
        return 'fixedDelay ' . $this->delay . 's';
    }
}
