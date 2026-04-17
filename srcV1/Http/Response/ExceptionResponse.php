<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\Base\HttpCode;
use JetBrains\PhpStorm\Deprecated;
use Psr\Log\LogLevel;

#[Deprecated]
class ExceptionResponse extends Exception
{
    protected $code = HttpCode::INTERNAL_SERVER_ERROR->value;
    protected string $logLevel = LogLevel::CRITICAL;
}
