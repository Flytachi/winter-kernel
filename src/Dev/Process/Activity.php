<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process;

/**
 * Whether the process is doing work right now.
 *
 * Orthogonal to {@see ProcessState} (which tracks the lifecycle). Activity is
 * BUSY while an inline unit is marked ({@see Process::markBusy()}) or any
 * {@see Process::spawn()} task is in flight; IDLE otherwise. It drives
 * drain-to-idle on stop, the status view, and (later) a daemon's scale-down
 * decision — never stop a BUSY worker.
 */
enum Activity
{
    case IDLE;
    case BUSY;
}
