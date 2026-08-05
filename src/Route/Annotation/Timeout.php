<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route\Annotation;

use Attribute;

/**
 * How long this route may run before the server stops waiting for it.
 *
 * Overrides the global deadline set by `ServerSettings::requestTimeout()`, per
 * controller or per method — the method wins when both are present:
 *
 * ```
 * #[Timeout(120)]                       // the whole controller gets two minutes
 * class ReportController extends Controller
 * {
 *     #[GetMapping('export')]
 *     #[Timeout(600)]                   // …but this export gets ten
 *     public function export(): ResponseEntity { ... }
 *
 *     #[GetMapping('ping')]
 *     #[Timeout(0)]                     // …and this one is never timed out
 *     public function ping(): string { ... }
 * }
 * ```
 *
 * `0` disables the deadline for the route. There is no way to say "use the global
 * value" other than omitting the attribute, which is the point of omitting it.
 *
 * ## What a timeout can and cannot interrupt
 *
 * A request that **waits** — on the database, an HTTP call, a file, `sleep()` — is
 * interrupted at the deadline: the wait raises `Swoole\Coroutine\CanceledException`,
 * `finally` and `defer` run (so transactions close and pooled connections go back),
 * and the client receives `504 Gateway Timeout`.
 *
 * A request stuck in **pure computation** is not interrupted, because the event loop
 * is single-threaded: while a handler loops without touching I/O nothing else in the
 * worker runs, the watchdog included. It will be noticed only once the loop ends.
 * (PHP-FPM has the mirror-image limitation: its `max_execution_time` kills a runaway
 * loop but not a hung query.)
 *
 * Application code that swallows the interruption — `catch (Throwable)` around the
 * whole handler — does not escape the deadline either: the watchdog keeps cancelling,
 * so no further I/O of that request can complete, and the framework discards whatever
 * the handler eventually returns in favour of the 504. It would otherwise answer 200
 * with a report built from queries that never ran.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Timeout
{
    /**
     * @param int $seconds Deadline in seconds; `0` disables it for this route.
     */
    public function __construct(public int $seconds)
    {
    }
}
