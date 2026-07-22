<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process;

/**
 * Lifecycle state of a {@see Process}.
 *
 * Mirrors the spirit of `java.lang.Thread.State`: a small, closed set of states
 * a managed unit moves through. {@see RESTARTING} is reserved for the supervised
 * {@see Daemon} layer (phase 2) and is never set by a bare process.
 */
enum ProcessState: int
{
    /** Constructed, not yet running. */
    case NEW = 0;
    /** Body is executing. */
    case RUNNING = 1;
    /** Stop signal received; draining before exit. */
    case STOPPING = 2;
    /** Body finished on its own. */
    case TERMINATED = 3;
    /** Body threw or exited abnormally. */
    case FAILED = 4;
    /** Supervisor is re-spawning the worker (Daemon layer). */
    case RESTARTING = 5;
}
