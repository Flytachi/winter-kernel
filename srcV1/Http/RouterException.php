<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

use Flytachi\Winter\Base\Exception\Exception;
use Psr\Log\LogLevel;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
class RouterException extends Exception
{
    protected string $logLevel = LogLevel::ALERT;
}
