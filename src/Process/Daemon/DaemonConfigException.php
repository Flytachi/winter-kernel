<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Daemon;

/**
 * Thrown when a {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon} has no
 * worker body — neither
 * {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon::workerRun()} is defined
 * nor {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon::$workerClass} is set —
 * or the configured worker class does not extend
 * {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process}.
 */
final class DaemonConfigException extends \RuntimeException
{
}
