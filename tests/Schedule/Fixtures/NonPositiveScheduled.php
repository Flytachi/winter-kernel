<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Fixtures;

use Flytachi\Winter\Kernel\Schedule\Scheduled;

/** A #[Scheduled] method with a non-positive period. */
final class NonPositiveScheduled
{
    #[Scheduled(fixedDelay: 0.0)]
    public function run(): void
    {
    }
}
