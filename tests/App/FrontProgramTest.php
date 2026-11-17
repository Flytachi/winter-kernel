<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\FrontProgram;
use Flytachi\Winter\Kernel\App\OutputLine;
use Flytachi\Winter\Kernel\App\ProgramOutput;
use PHPUnit\Framework\TestCase;

/** What a frontend runs: a server of its own on a loopback port. */
final class FrontProgramTest extends TestCase
{
    public function test_a_program_takes_a_command_and_its_environment(): void
    {
        $output = new class implements ProgramOutput {
            public function line(string $line): OutputLine
            {
                return OutputLine::skip();
            }
        };
        $program = new FrontProgram(['node', 'server.mjs'], ['NODE_ENV' => 'production'], $output, 90);

        self::assertSame(['node', 'server.mjs'], $program->command);
        self::assertSame(['NODE_ENV' => 'production'], $program->env);
        self::assertSame($output, $program->output);
        self::assertSame(90, $program->startTimeout);
    }

    public function test_it_refuses_what_exec_cannot_take(): void
    {
        $bad = [
            'empty command' => fn() => new FrontProgram([]),
            'empty program' => fn() => new FrontProgram(['']),
            'not a string' => fn() => new FrontProgram(['node', 3]),
            'variable name' => fn() => new FrontProgram(['node'], ['1A' => 'x']),
            'two lines' => fn() => new FrontProgram(['node'], ['A' => "x\ny"]),
            'not a string value' => fn() => new FrontProgram(['node'], ['A' => 1]),
            'negative timeout' => fn() => new FrontProgram(['node'], startTimeout: -1),
        ];
        foreach ($bad as $what => $make) {
            try {
                $make();
                self::fail("accepted: {$what}");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
