<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Schedule;

use Attribute;

/**
 * Marks a method to be run on a schedule by the {@see Scheduler}.
 *
 * Spring-style declarative scheduling: annotate a public, zero-argument method of
 * any DI-resolvable class, then run the scheduler process — it discovers every
 * annotated method and fires it on the configured trigger. The class is resolved
 * from the container per invocation (its `#[Autowired]` dependencies included), so
 * the method is a plain instance method, not static.
 *
 * Exactly one trigger must be set: {@see $fixedDelay}, {@see $fixedRate} or
 * {@see $cron}. The period timings are in seconds (float), matching the rest of
 * the kernel ({@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::sleep()} / grace); the
 * cron expression is clock-aligned (see {@see \Flytachi\Winter\Kernel\Schedule\Trigger\CronTrigger}).
 * A misconfigured attribute is rejected at discovery with a {@see ScheduleConfigException}.
 *
 * ```
 * class ReportService
 * {
 *     // 5s after each run finishes
 *     #[Scheduled(fixedDelay: 5.0)]
 *     public function flush(): void
 *     {
 *         // ... work ...
 *     }
 *
 *     // every 2s, first run after 10s
 *     #[Scheduled(fixedRate: 2.0, initialDelay: 10.0)]
 *     public function poll(): void
 *     {
 *         // ... work ...
 *     }
 *
 *     // every day at 02:00, clock-aligned
 *     #[Scheduled(cron: '0 2 * * *')]
 *     public function nightly(): void
 *     {
 *         // ... work ...
 *     }
 * }
 * ```
 *
 * Repeatable, so one method may carry several triggers.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Scheduled
{
    /**
     * @param float|null $fixedDelay Seconds between the END of one run and the START of the next.
     * @param float|null $fixedRate Seconds between successive STARTs (a long run never overlaps itself).
     * @param float $initialDelay Seconds to wait before the first run (period triggers only; not with cron).
     * @param string|null $cron Clock-aligned cron expression, e.g. '0 2 * * *' — five fields or a macro.
     */
    public function __construct(
        public ?float $fixedDelay = null,
        public ?float $fixedRate = null,
        public float $initialDelay = 0.0,
        public ?string $cron = null,
    ) {
    }
}
