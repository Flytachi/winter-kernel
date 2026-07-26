<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App;

/**
 * The kind of a long-lived {@see Component} an {@see \Flytachi\Winter\K2\Application}
 * hosts. Http is the main server; the rest are supervised companions.
 */
enum ComponentKind
{
    case Http;
    case WebSocket;
    case Process;
    case Daemon;
    case Scheduler;
}
