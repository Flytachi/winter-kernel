<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which command lines start the application and which go to the console. `run --help`
 * used to start it: the entry took `run` before the console could answer the question.
 */
final class StartsTheApplicationTest extends TestCase
{
    /** @return iterable<string, array{list<string>, bool}> */
    public static function lines(): iterable
    {
        yield 'run' => [['call', 'run'], true];
        yield 'run dev' => [['call', 'run', 'dev'], true];
        yield 'run with a flag' => [['call', 'run', '--http.port=8001'], true];
        yield 'application start' => [['call', 'application', 'start'], true];
        yield 'app start dev' => [['call', 'app', 'start', 'dev'], true];
        yield 'run --help' => [['call', 'run', '--help'], false];
        yield 'run -h' => [['call', 'run', '-h'], false];
        yield 'run dev --help' => [['call', 'run', 'dev', '--help'], false];
        yield 'app start --help' => [['call', 'app', 'start', '--help'], false];
        yield 'application start -h' => [['call', 'application', 'start', '-h'], false];
        yield 'app status' => [['call', 'app', 'status'], false];
        yield 'bare' => [['call'], false];
    }

    /** @param list<string> $argv */
    #[DataProvider('lines')]
    public function test_which_lines_start_the_application(array $argv, bool $starts): void
    {
        $check = new \ReflectionMethod(WinterApplication::class, 'startsTheApplication');

        self::assertSame($starts, $check->invoke(null, ApplicationArguments::parse($argv)));
    }
}
