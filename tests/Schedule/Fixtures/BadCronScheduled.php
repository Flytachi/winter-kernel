<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule\Fixtures;

use Flytachi\Winter\K2\Schedule\Scheduled;

/** A #[Scheduled] method with a malformed cron expression. */
final class BadCronScheduled
{
    #[Scheduled(cron: 'not a cron')]
    public function run(): void
    {
    }
}
