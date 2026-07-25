<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule\Fixtures;

use Flytachi\Winter\K2\Schedule\Scheduled;

/** A #[Scheduled] method with two triggers set at once. */
final class TwoTriggerScheduled
{
    #[Scheduled(fixedDelay: 5.0, fixedRate: 2.0)]
    public function run(): void
    {
    }
}
