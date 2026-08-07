<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\File;

use Flytachi\Winter\Base\Exception\ExceptionLogLevel;
use Flytachi\Winter\Base\Exception\ExceptionTrait;
use Psr\Log\LogLevel;

class FileException extends \RuntimeException implements ExceptionLogLevel
{
    use ExceptionTrait;

    public function getLogLevel(): string
    {
        return LogLevel::CRITICAL;
    }
}
