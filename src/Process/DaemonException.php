<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process;

use Flytachi\Winter\Base\Exception\ExceptionTrait;
use Psr\Log\LogLevel;

class DaemonException extends \RuntimeException
{
    use ExceptionTrait;

    protected string $logLevel = LogLevel::CRITICAL;
}
