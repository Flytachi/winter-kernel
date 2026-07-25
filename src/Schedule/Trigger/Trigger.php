<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Schedule\Trigger;

/**
 * Computes when a scheduled task should next fire.
 *
 * Mirrors Spring's `Trigger`: a stateless strategy handed the timing of the last
 * run, returning the next fire time. All times are wall-clock seconds since the
 * epoch (`microtime(true)`), so a period trigger reacts to elapsed real time and a
 * cron trigger can align to the clock (02:00, the top of the minute, …). The
 * scheduler owns the task state; the trigger only answers "given the last run,
 * when is the next one" and "given boot, when is the first one".
 */
interface Trigger
{
    /**
     * The first fire time after boot, in wall-clock seconds.
     *
     * A period trigger fires at boot plus the initial delay; a clock-aligned
     * trigger (cron) ignores the initial delay and fires at its next matching
     * instant.
     *
     * @param float $now Current wall-clock time (boot).
     * @param float $initialDelay Seconds to wait before the first run (period triggers only).
     */
    public function firstFireTime(float $now, float $initialDelay): float;

    /**
     * The next fire time after a run, in wall-clock seconds.
     *
     * @param float $now Current wall-clock time.
     * @param float|null $lastStartAt When the last run started, or null before the first run.
     * @param float|null $lastEndAt When the last run finished, or null before the first run.
     */
    public function nextFireTime(float $now, ?float $lastStartAt, ?float $lastEndAt): float;

    /**
     * Human-readable description of the cadence, for logs and the CLI listing.
     */
    public function describe(): string;
}
