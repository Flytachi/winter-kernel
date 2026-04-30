<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\File;

use Flytachi\Winter\Base\Exception\ExceptionTrait;
use Flytachi\Winter\K2\Exception\LogLevelException;
use Psr\Log\LogLevel;

class FileException extends \RuntimeException implements LogLevelException
{
    use ExceptionTrait;

    public function getLogLevel(): string
    {
        return LogLevel::CRITICAL;
    }
}
