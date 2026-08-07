<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Fixtures;

use Flytachi\Winter\Kernel\Schedule\Scheduled;

/** A #[Scheduled] method that requires an argument. */
final class ArgScheduled
{
    #[Scheduled(fixedDelay: 5.0)]
    public function run(int $x): void
    {
    }
}
