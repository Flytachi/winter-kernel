<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

/** A dependency that cannot be built — a misconfiguration that breaks a start. */
final class Explosive
{
    public function __construct()
    {
        throw new \RuntimeException('cannot be built');
    }
}
