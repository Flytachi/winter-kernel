<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule\Fixtures;

use Flytachi\Winter\K2\Schedule\Scheduled;

/** A #[Scheduled] method that requires an argument. */
final class ArgScheduled
{
    #[Scheduled(fixedDelay: 5.0)]
    public function run(int $x): void
    {
    }
}
