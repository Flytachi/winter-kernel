<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\App;

use Flytachi\Winter\K2\App\ApplicationArguments;
use PHPUnit\Framework\TestCase;

final class ApplicationArgumentsTest extends TestCase
{
    public function test_command_and_sub(): void
    {
        $args = ApplicationArguments::parse(['call', 'run', 'dev']);
        self::assertSame('run', $args->command());
        self::assertSame('dev', $args->sub());
    }

    public function test_bare_invocation_has_no_command(): void
    {
        $args = ApplicationArguments::parse(['call']);
        self::assertNull($args->command());
        self::assertNull($args->sub());
    }

    public function test_long_options_with_and_without_value(): void
    {
        $args = ApplicationArguments::parse(['call', 'run', '--port=8080', '--watcher']);
        self::assertSame('8080', $args->option('port'));
        self::assertSame(8080, $args->int('port', 8000));
        self::assertTrue($args->has('watcher'));
        self::assertNull($args->option('watcher'));          // present, no value
        self::assertSame('0.0.0.0', $args->option('host', '0.0.0.0'));
    }

    public function test_int_falls_back_when_absent_or_non_numeric(): void
    {
        $args = ApplicationArguments::parse(['call', 'run', '--port=abc']);
        self::assertSame(8000, $args->int('port', 8000));
        self::assertSame(9000, $args->int('missing', 9000));
    }

    public function test_short_flags_expand(): void
    {
        $args = ApplicationArguments::parse(['call', 'run', '-w']);
        self::assertTrue($args->flag('w'));
        self::assertFalse($args->flag('x'));
    }

    public function test_raw_is_preserved_for_console_handoff(): void
    {
        $argv = ['call', 'make', '-c', 'UserController'];
        $args = ApplicationArguments::parse($argv);
        self::assertSame($argv, $args->raw());
        self::assertSame('make', $args->command());
    }
}
