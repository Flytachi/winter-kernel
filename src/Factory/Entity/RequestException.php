<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory\Entity;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\Base\HttpCode;
use Psr\Log\LogLevel;

class RequestException extends Exception
{
    protected $code = HttpCode::BAD_REQUEST->value;
    protected string $logLevel = LogLevel::WARNING;
}
