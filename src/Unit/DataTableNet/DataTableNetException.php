<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\DataTableNet;

use Flytachi\Winter\Base\Exception\ExceptionTrait;
use Psr\Log\LogLevel;

class DataTableNetException extends \RuntimeException
{
    use ExceptionTrait;

    protected string $logLevel = LogLevel::ERROR;
}
