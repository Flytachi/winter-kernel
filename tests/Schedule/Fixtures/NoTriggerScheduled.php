<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Fixtures;

use Flytachi\Winter\Kernel\Schedule\Scheduled;

/** A #[Scheduled] method with no trigger set. */
final class NoTriggerScheduled
{
    #[Scheduled]
    public function run(): void
    {
    }
}
