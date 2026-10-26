<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * The kind of a long-lived {@see Component} a {@see \Flytachi\Winter\Kernel\WinterApplication}
 * hosts. Http is the main server; the rest are supervised companions. A Command is an
 * outside program — a script, any executable — the application only starts and watches.
 */
enum ComponentKind
{
    case Http;
    case WebSocket;
    case Process;
    case Daemon;
    case Scheduler;
    case Command;
}
