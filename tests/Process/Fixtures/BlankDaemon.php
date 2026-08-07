<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/**
 * Misconfigured daemon: neither workerRun() nor $workerClass — bootWorker() must
 * throw DaemonConfigException.
 */
final class BlankDaemon extends Daemon
{

}
