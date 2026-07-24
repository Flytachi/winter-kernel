<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Daemon\Daemon;

/**
 * Misconfigured daemon: neither workerRun() nor $workerClass — bootWorker() must
 * throw DaemonConfigException.
 */
final class BlankDaemon extends Daemon
{

}
