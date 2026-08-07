<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

/**
 * Thrown by {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::start()} or
 * {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::dispatch()} when an
 * instance of the same class is already running.
 *
 * A process is a singleton per class: one class means one running instance. To
 * run several workers of the same logic, use a
 * {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon} with replicas, or
 * distinct classes.
 */
final class ProcessAlreadyRunningException extends \RuntimeException
{
}
