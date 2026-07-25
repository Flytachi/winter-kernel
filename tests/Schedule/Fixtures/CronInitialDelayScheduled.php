<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule\Fixtures;

use Flytachi\Winter\K2\Schedule\Scheduled;

/** A #[Scheduled] cron method that also (illegally) sets an initial delay. */
final class CronInitialDelayScheduled
{
    #[Scheduled(cron: '0 2 * * *', initialDelay: 5.0)]
    public function run(): void
    {
    }
}
