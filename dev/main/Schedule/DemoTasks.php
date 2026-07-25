<?php

declare(strict_types=1);

namespace Main\Schedule;

use Flytachi\Winter\K2\Schedule\Scheduled;
use Flytachi\Winter\Logger\LoggerFactory;

/**
 * Demo of declarative scheduling: two methods on a plain class, each fired by the
 * Scheduler on its own trigger. Run with `php dev/call schedule start` (or
 * `... start -d` for the background) and `php dev/call schedule list` to see them.
 */
class DemoTasks
{
    /**
     * fixedRate: starts every 2s regardless of how long the body takes.
     */
    #[Scheduled(fixedRate: 2.0)]
    public function heartbeat(): void
    {
        LoggerFactory::getLogger(self::class)->info('DemoTasks::heartbeat (fixedRate 2s)');
    }

    /**
     * fixedDelay with an initial delay: first run after 3s, then 5s after each
     * run finishes. The 1s of work here shows the delay is measured from the end.
     */
    #[Scheduled(fixedDelay: 5.0, initialDelay: 3.0)]
    public function report(): void
    {
        $log = LoggerFactory::getLogger(self::class);
        $log->info('DemoTasks::report START (fixedDelay 5s, initialDelay 3s)');
        sleep(1);
        $log->info('DemoTasks::report DONE');
    }

    /**
     * cron: every night at 02:00, clock-aligned.
     */
    #[Scheduled(cron: '0 2 * * *')]
    public function nightly(): void
    {
        LoggerFactory::getLogger(self::class)->info('DemoTasks::nightly (cron 0 2 * * *)');
    }

    /**
     * cron: every morning at 08:00 on weekdays.
     */
    #[Scheduled(cron: '0 8 * * 1-5')]
    public function morning(): void
    {
        LoggerFactory::getLogger(self::class)->info('DemoTasks::morning (cron 0 8 * * 1-5)');
    }
}
