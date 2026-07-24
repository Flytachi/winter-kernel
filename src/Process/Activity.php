<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process;

/**
 * Whether the process is doing work right now.
 *
 * Orthogonal to {@see ProcessState} (which tracks the lifecycle). Activity is
 * BUSY while an inline unit is marked ({@see Process::markBusy()}) or any
 * {@see Process::spawn()} task is in flight; IDLE otherwise. It drives
 * drain-to-idle on stop, the status view, and (later) a daemon's scale-down
 * decision — never stop a BUSY worker.
 *
 * Backed by a string so it serialises cleanly to JSON and logs.
 */
enum Activity: string
{
    case IDLE = 'idle';
    case BUSY = 'busy';
}
