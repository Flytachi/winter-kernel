<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\File;

use Flytachi\Winter\Base\Exception\Exception;
use Flytachi\Winter\K2\Exception\LogLevelException;
use Psr\Log\LogLevel;

class FileException extends Exception implements LogLevelException
{
    public function getLogLevel(): string
    {
        return LogLevel::CRITICAL;
    }
}
