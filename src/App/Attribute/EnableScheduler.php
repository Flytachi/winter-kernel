<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler;

/**
 * Enables the scheduler that runs #[Scheduled] tasks — the analogue of Spring's
 * `@EnableScheduling`. Declared on the {@see \Flytachi\Winter\Kernel\WinterApplication}
 * class; produces one {@see \Flytachi\Winter\Kernel\App\Component::scheduler()} in the
 * manifest.
 *
 * ```
 * #[EnableScheduler]                     // built-in scheduler
 * #[EnableScheduler(MyScheduler::class)] // a subclass overriding discovery
 * final class App extends WinterApplication { ... }
 * ```
 *
 * @link https://winterframe.net/docs/components Running the #[Scheduled] scheduler with the app
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EnableScheduler
{
    /**
     * @param class-string<Scheduler> $class Scheduler class (default: the built-in one).
     * @param bool $critical Whether the application stops when it cannot run (the default);
     *                       false leaves it down, marked failed, while the rest runs on.
     * @param int $retries Restarts after it crashed while running, within 30 minutes.
     *                     A failure to start is never retried.
     * @param int $startTimeout Seconds to become ready (to start its loop); 0 waits for good.
     */
    public function __construct(
        public string $class = Scheduler::class,
        public bool $critical = true,
        public int $retries = Component::RETRIES,
        public int $startTimeout = Component::START_TIMEOUT,
    ) {
    }
}
