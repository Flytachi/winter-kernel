<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\DataTableNet;

use Flytachi\Winter\Base\Exception\Exception;
use Psr\Log\LogLevel;

class DataTableNetException extends Exception
{
    protected string $logLevel = LogLevel::ERROR;
}
