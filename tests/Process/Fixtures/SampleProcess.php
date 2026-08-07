<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Process;

/**
 * Minimal bare process for title / afterFork tests. Never actually run.
 */
final class SampleProcess extends Process
{
    public function run(): void
    {
    }
}
