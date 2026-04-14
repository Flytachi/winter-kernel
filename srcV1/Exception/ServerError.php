<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Exception;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\Base\HttpCode;
use Psr\Log\LogLevel;

class ServerError extends Exception
{
    protected $code = HttpCode::INTERNAL_SERVER_ERROR->value;
    protected string $logLevel = LogLevel::ERROR;
}
