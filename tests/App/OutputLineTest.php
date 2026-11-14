<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\OutputLine;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\TaggedOutput;
use PHPUnit\Framework\TestCase;

/** What a line of a program's output can mean, and the parser a command carries. */
final class OutputLineTest extends TestCase
{
    public function test_an_entry_a_continuation_and_a_skip(): void
    {
        $entry = OutputLine::entry('error', 'boom', ['a' => 1], pid: 57);
        self::assertSame(['error', 'boom', ['a' => 1], 57, false], [$entry->level, $entry->message, $entry->context, $entry->pid, $entry->skip]);

        $more = OutputLine::more('SELECT 1', 'statement');
        self::assertNull($more->level);
        self::assertSame('statement', $more->key);

        self::assertTrue(OutputLine::skip()->skip);
    }

    public function test_a_level_that_is_not_psr3_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('LOG is not a PSR-3 log level.');
        OutputLine::entry('LOG', 'x');
    }

    public function test_a_command_keeps_its_parser_when_bound_elsewhere(): void
    {
        $parser = new TaggedOutput();
        $command = Component::command(['sleep', '1'], 'db', output: $parser);

        self::assertSame($parser, $command->output);
        self::assertSame($parser, $command->at('127.0.0.1', 9000)->output);
        self::assertNull(Component::command(['sleep', '1'])->output);
    }
}
