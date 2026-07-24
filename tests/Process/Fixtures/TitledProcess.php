<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Process;

/**
 * Bare process with an explicit title, to check titleName() precedence.
 */
final class TitledProcess extends Process
{
    protected ?string $processTitle = 'custom-title';

    public function run(): void
    {
    }
}
