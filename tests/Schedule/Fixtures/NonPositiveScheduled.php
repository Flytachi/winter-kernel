<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule\Fixtures;

use Flytachi\Winter\K2\Schedule\Scheduled;

/** A #[Scheduled] method with a non-positive period. */
final class NonPositiveScheduled
{
    #[Scheduled(fixedDelay: 0.0)]
    public function run(): void
    {
    }
}
