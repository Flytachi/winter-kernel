<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule\Fixtures;

use Flytachi\Winter\K2\Schedule\Scheduled;

/** A #[Scheduled] method with no trigger set. */
final class NoTriggerScheduled
{
    #[Scheduled]
    public function run(): void
    {
    }
}
