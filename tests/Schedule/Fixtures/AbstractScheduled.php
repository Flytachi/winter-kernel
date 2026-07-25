<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule\Fixtures;

use Flytachi\Winter\K2\Schedule\Scheduled;

/** A #[Scheduled] method on a non-instantiable (abstract) class. */
abstract class AbstractScheduled
{
    #[Scheduled(fixedDelay: 5.0)]
    public function run(): void
    {
    }
}
