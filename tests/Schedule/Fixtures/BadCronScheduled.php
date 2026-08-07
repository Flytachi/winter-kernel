<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Fixtures;

use Flytachi\Winter\Kernel\Schedule\Scheduled;

/** A #[Scheduled] method with a malformed cron expression. */
final class BadCronScheduled
{
    #[Scheduled(cron: 'not a cron')]
    public function run(): void
    {
    }
}
