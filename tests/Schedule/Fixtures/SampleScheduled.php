<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Fixtures;

use Flytachi\Winter\Kernel\Schedule\Scheduled;

/**
 * A well-formed target: two triggered methods plus a plain one the collector must
 * ignore. Public counters let a test assert invocation without forks.
 */
final class SampleScheduled
{
    public int $delayRuns = 0;
    public int $rateRuns = 0;

    #[Scheduled(fixedDelay: 5.0)]
    public function onDelay(): void
    {
        $this->delayRuns++;
    }

    #[Scheduled(fixedRate: 2.0, initialDelay: 1.5)]
    public function onRate(): void
    {
        $this->rateRuns++;
    }

    public function notScheduled(): void
    {
    }
}
