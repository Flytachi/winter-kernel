<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory\Middleware;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\Base\HttpCode;
use Psr\Log\LogLevel;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
class MiddlewareException extends Exception
{
    protected $code = HttpCode::UNAUTHORIZED->value;
    protected string $logLevel = LogLevel::WARNING;
}
