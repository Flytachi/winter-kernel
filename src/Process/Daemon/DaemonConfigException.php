<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Daemon;

/**
 * Thrown when a {@see Daemon} has no worker body — neither {@see Daemon::workerRun()}
 * is defined nor {@see Daemon::$workerClass} is set — or the configured worker
 * class does not extend {@see \Flytachi\Winter\K2\Process\Process}.
 */
final class DaemonConfigException extends \RuntimeException
{
}
