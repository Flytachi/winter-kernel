<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Fixtures;

use Flytachi\Winter\Kernel\Schedule\Scheduled;

/** A #[Scheduled] method on a non-instantiable (abstract) class. */
abstract class AbstractScheduled
{
    #[Scheduled(fixedDelay: 5.0)]
    public function run(): void
    {
    }
}
