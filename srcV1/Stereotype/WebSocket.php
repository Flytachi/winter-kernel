<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Stereotype;

use Flytachi\Winter\Kernel\Process\Socket\Web\ThreadWebSocket;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
abstract class WebSocket extends ThreadWebSocket
{
}
