<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Supervision;

/**
 * Where a supervised component stands.
 *
 * @internal
 */
enum UnitState
{
    /** Not running yet: the first start, or a restart after its back-off. */
    case Waiting;
    /** Its process runs and has not said it is ready. */
    case Starting;
    /** Its process runs and is ready. */
    case Running;
    /** It ended because it was asked to stop — not restarted. */
    case Stopped;
    /** A process finished its work — not restarted. */
    case Completed;
    /** It could not run and is not critical: left down while the rest runs on. */
    case Failed;
    /** A process outside the application runs it: started once that one ends. */
    case Held;
}
