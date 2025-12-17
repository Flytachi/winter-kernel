<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\File;

use Flytachi\Winter\Base\Exception\Exception;
use Psr\Log\LogLevel;

class FileException extends Exception
{
    protected string $logLevel = LogLevel::ERROR;
}
