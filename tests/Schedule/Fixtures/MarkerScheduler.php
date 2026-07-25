<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule\Fixtures;

use Flytachi\Winter\K2\Schedule\ScheduledTask;
use Flytachi\Winter\K2\Schedule\Scheduler;
use Flytachi\Winter\K2\Schedule\Trigger\FixedRateTrigger;

/**
 * Integration fixture: a real scheduler whose task registry is injected (via the
 * {@see Scheduler::discover()} override) rather than scanned, so a test can drive
 * the true boot → fire (spawn) → stop loop against {@see MarkerTask} without
 * depending on a project-wide class scan.
 */
final class MarkerScheduler extends Scheduler
{
    /**
     * {@inheritDoc}
     */
    protected function discover(): array
    {
        return [
            new ScheduledTask(MarkerTask::class, 'tick', new FixedRateTrigger(0.2)),
        ];
    }
}
