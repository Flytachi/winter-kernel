<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

use Flytachi\Winter\Base\Exception\Exception;
use Psr\Log\LogLevel;

class RouterException extends Exception
{
    protected string $logLevel = LogLevel::ALERT;
}
