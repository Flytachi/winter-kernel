<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

use Flytachi\Winter\Kernel\App\OutputLine;
use Flytachi\Winter\Kernel\App\ProgramOutput;

/**
 * Reads a test program's lines: `E:text` an error, `W 42:text` a warning written by
 * process 42, `D:text` its detail, `>text` more of whatever came last, `skip` nothing,
 * `boom` a line it cannot read; anything else is info.
 */
final class TaggedOutput implements ProgramOutput
{
    public function line(string $line): OutputLine
    {
        return match (true) {
            $line === 'skip' => OutputLine::skip(),
            $line === 'boom' => throw new \RuntimeException('unreadable'),
            str_starts_with($line, 'E:') => OutputLine::entry('error', substr($line, 2)),
            preg_match('/^W (\d+):(.*)$/', $line, $m) === 1 => OutputLine::entry('warning', $m[2], pid: (int) $m[1]),
            str_starts_with($line, 'D:') => OutputLine::more(substr($line, 2), 'detail'),
            str_starts_with($line, '>') => OutputLine::more(substr($line, 1)),
            default => OutputLine::entry('info', $line),
        };
    }
}
