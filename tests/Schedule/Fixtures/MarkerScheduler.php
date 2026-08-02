<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Fixtures;

use Flytachi\Winter\Kernel\Schedule\ScheduledTask;
use Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler;
use Flytachi\Winter\Kernel\Schedule\Trigger\FixedRateTrigger;

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
