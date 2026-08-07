<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Fixtures;

use Flytachi\Winter\Kernel\Schedule\Scheduled;

/** A valid #[Scheduled] cron method — every day at 02:00. */
final class CronScheduled
{
    #[Scheduled(cron: '0 2 * * *')]
    public function run(): void
    {
    }
}
