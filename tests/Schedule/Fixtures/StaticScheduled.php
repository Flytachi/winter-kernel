<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule\Fixtures;

use Flytachi\Winter\K2\Schedule\Scheduled;

/** A #[Scheduled] static method (not invocable on a resolved instance). */
final class StaticScheduled
{
    #[Scheduled(fixedDelay: 5.0)]
    public static function run(): void
    {
    }
}
