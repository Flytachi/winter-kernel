<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

/**
 * Whether the process is doing work right now.
 *
 * Orthogonal to {@see ProcessState} (which tracks the lifecycle). Activity is
 * BUSY while an inline unit is marked ({@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::markBusy()}) or any
 * {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::spawn()} task is in flight; IDLE otherwise. It drives
 * drain-to-idle on stop, the status view, and (later) a daemon's scale-down
 * decision — never stop a BUSY worker.
 *
 * Backed by a string so it serialises cleanly to JSON and logs.
 *
 * @link https://winterframe.net/docs/processes Marking units of work: markBusy / markIdle
 */
enum Activity: string
{
    case IDLE = 'idle';
    case BUSY = 'busy';
}
