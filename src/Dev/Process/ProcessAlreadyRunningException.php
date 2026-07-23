<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process;

/**
 * Thrown by {@see Process::start()} / {@see Process::dispatch()} when an instance
 * of the same class is already running.
 *
 * A process is a singleton per class: one class means one running instance. To
 * run several workers of the same logic, use a {@see Daemon} with replicas, or
 * distinct classes.
 */
final class ProcessAlreadyRunningException extends \RuntimeException
{
}
