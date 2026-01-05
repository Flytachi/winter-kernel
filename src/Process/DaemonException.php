<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

use Flytachi\Winter\Base\Exception\Exception;
use Psr\Log\LogLevel;

class DaemonException extends Exception
{
    protected string $logLevel = LogLevel::CRITICAL;
}
