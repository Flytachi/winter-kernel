<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Process;

/**
 * Minimal bare process for title / afterFork tests. Never actually run.
 */
final class SampleProcess extends Process
{
    public function run(): void
    {
    }
}
