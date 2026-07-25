<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Schedule;

/**
 * Thrown when a {@see Scheduled} method is misconfigured — no trigger set, more
 * than one trigger set, the method is not callable without arguments, or its
 * declaring class cannot be instantiated by the container.
 */
final class ScheduleConfigException extends \RuntimeException
{
}
