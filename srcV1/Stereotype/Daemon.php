<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Stereotype;

use Flytachi\Winter\Kernel\Process\ThreadDaemon;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
abstract class Daemon extends ThreadDaemon
{
}
