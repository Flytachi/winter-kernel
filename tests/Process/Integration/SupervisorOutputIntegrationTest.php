<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision\TaggedOutput;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * An outside program's output, parsed: its stdout and stderr come to the supervisor line
 * by line and go into the log under the program's name, with levels, details joined to
 * their entry and the PID of whoever wrote them. Without a parser it is not touched.
 */
#[Group('integration')]
final class SupervisorOutputIntegrationTest extends IntegrationCase
{
    use SupervisesComponents;

    /** The program logs: each entry a JSON line in `programs`. */
    private function programLog(): \Closure
    {
        $file = $this->storage . '/programs';
        return static fn(string $name): LoggerInterface => new class ($name, $file) extends AbstractLogger {
            public function __construct(private readonly string $name, private readonly string $file)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $line = json_encode(['name' => $this->name, 'level' => $level, 'message' => (string) $message] + $context);
                file_put_contents($this->file, $line . "\n", FILE_APPEND);
            }
        };
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        $lines = @file($this->storage . '/programs', FILE_IGNORE_NEW_LINES) ?: [];
        return array_map(static fn(string $l): array => json_decode($l, true), $lines);
    }

    public function test_the_output_is_parsed_into_the_log(): void
    {
        $script = 'echo "started"; echo "E:relation missing"; echo ">second line" >&2; '
            . 'echo "D:SELECT 1"; echo ">FROM x"; echo skip; echo "W 42:slow"; echo boom; printf "last"';
        $supervisor = $this->supervise(
            [Component::command(['sh', '-c', $script], 'db', output: new TaggedOutput())],
            programLog: $this->programLog(),
        );
        self::assertSame(0, $this->exitCode($supervisor), $this->logged() . $this->announced());

        $entries = $this->entries();
        $pid = $entries[0]['program'] ?? null;
        self::assertIsInt($pid);
        self::assertGreaterThan(0, $pid);
        $without = array_map(static function (array $e): array {
            unset($e['program']);
            return $e;
        }, $entries);
        self::assertSame([
            ['name' => 'db', 'level' => 'info', 'message' => 'started'],
            ['name' => 'db', 'level' => 'error', 'message' => "relation missing\nsecond line", 'detail' => "SELECT 1\nFROM x"],
            ['name' => 'db', 'level' => 'warning', 'message' => 'slow'],
            ['name' => 'db', 'level' => 'info', 'message' => 'boom'],
            ['name' => 'db', 'level' => 'info', 'message' => 'last'],
        ], $without, 'stderr too, skip dropped, an unreadable line kept, the last one without its newline');
        self::assertSame(42, $entries[2]['program'], 'the PID the line names');
        self::assertSame($pid, $entries[3]['program']);
    }

    public function test_an_entry_is_logged_once_the_program_goes_quiet(): void
    {
        $supervisor = $this->supervise(
            [Component::command(['sh', '-c', 'echo "E:first"; sleep 30'], 'db', output: new TaggedOutput())],
            programLog: $this->programLog(),
        );
        $file = $this->storage . '/programs';
        self::assertTrue($this->pollUntil(static fn() => str_contains((string) @file_get_contents($file), 'first'), 3.0));

        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    public function test_without_a_parser_the_output_is_left_alone(): void
    {
        $supervisor = $this->supervise(
            [Component::command(['sh', '-c', 'echo plain'], 'db')],
            programLog: $this->programLog(),
        );
        self::assertSame(0, $this->exitCode($supervisor));
        self::assertSame([], $this->entries());
    }

    public function test_a_busy_program_does_not_hold_the_supervisor(): void
    {
        $supervisor = $this->supervise(
            [Component::command(['sh', '-c', 'yes "a line of output" | head -n 200000'], 'db', output: new TaggedOutput())],
            programLog: static fn(string $name): LoggerInterface => new \Psr\Log\NullLogger(),
        );
        self::assertSame(0, $this->exitCode($supervisor, 30.0));
        self::assertStringContainsString('db finished its work.', $this->logged());
    }
}
