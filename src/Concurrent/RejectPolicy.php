<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Concurrent;

/**
 * What a bounded executor does with a task it cannot accept — when every worker
 * slot is busy and the wait queue is full.
 *
 * Mirrors the handlers of `java.util.concurrent.ThreadPoolExecutor`. The policy
 * is chosen per pool at construction ({@see Executors::newFixedExecutor()}); it
 * only applies when a bounded queue (`queue > 0`) is actually full — an unbounded
 * pool never rejects.
 */
enum RejectPolicy
{
    /** Throw {@see RejectedExecutionException} — the caller learns the pool is saturated. */
    case ABORT;
    /** Run the task synchronously in the calling context — natural back-pressure. */
    case CALLER_RUNS;
    /** Silently drop the task; a submitted future comes back cancelled. */
    case DISCARD;
}
