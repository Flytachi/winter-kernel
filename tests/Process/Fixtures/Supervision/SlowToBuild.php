<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

/** A dependency that hangs while built — a connection that never answers. */
final class SlowToBuild
{
    public function __construct()
    {
        sleep(30);
    }
}
