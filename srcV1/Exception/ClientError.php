<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Exception;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\Base\HttpCode;
use Psr\Log\LogLevel;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
class ClientError extends Exception
{
    protected $code = HttpCode::CONFLICT->value;
    protected string $logLevel = LogLevel::WARNING;
}
