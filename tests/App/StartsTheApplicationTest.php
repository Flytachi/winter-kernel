<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\Core\ScanCache;
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

    /** @return iterable<string, array{list<string>, ScanCache}> */
    public static function cacheModes(): iterable
    {
        yield 'run' => [['call', 'run'], ScanCache::Refresh];
        yield 'run with a flag' => [['call', 'run', '--http.port=8001'], ScanCache::Refresh];
        yield 'application start' => [['call', 'application', 'start'], ScanCache::Refresh];
        yield 'run dev' => [['call', 'run', 'dev'], ScanCache::Live];
        yield 'run -w' => [['call', 'run', '-w'], ScanCache::Live];
        yield 'run --watcher' => [['call', 'run', '--watcher'], ScanCache::Live];
        yield 'app start dev' => [['call', 'app', 'start', 'dev'], ScanCache::Live];
        yield 'run --help' => [['call', 'run', '--help'], ScanCache::Read];
        yield 'run dev --help' => [['call', 'run', 'dev', '--help'], ScanCache::Read];
        yield 'app status' => [['call', 'app', 'status'], ScanCache::Read];
        yield 'app health' => [['call', 'app', 'health'], ScanCache::Read];
        yield 'mapping' => [['call', 'mapping'], ScanCache::Read];
        yield 'bare' => [['call'], ScanCache::Read];
    }

    /**
     * Only a start writes the class list: a command run before a class was added used to
     * leave the list that hid it from the next start; `run dev` walks every time.
     *
     * @param list<string> $argv
     */
    #[DataProvider('cacheModes')]
    public function test_what_each_line_does_with_the_class_list(array $argv, ScanCache $mode): void
    {
        $check = new \ReflectionMethod(WinterApplication::class, 'scanCacheFor');

        self::assertSame($mode, $check->invoke(null, ApplicationArguments::parse($argv)));
    }
}
