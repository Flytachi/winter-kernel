<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use Flytachi\Winter\Kernel\Process\ForkReset;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\SampleProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\TitledProcess;
use PHPUnit\Framework\TestCase;

final class ProcessTest extends TestCase
{
    protected function setUp(): void
    {
        ForkReset::clear();
    }

    protected function tearDown(): void
    {
        ForkReset::clear();
    }

    private function invoke(Process $p, string $method): mixed
    {
        $m = new \ReflectionMethod($p, $method);
        return $m->invoke($p);
    }

    public function test_title_name_defaults_to_short_class_name(): void
    {
        self::assertSame('SampleProcess', $this->invoke(new SampleProcess(), 'titleName'));
    }

    public function test_title_name_prefers_explicit_process_title(): void
    {
        self::assertSame('custom-title', $this->invoke(new TitledProcess(), 'titleName'));
    }

    public function test_build_process_title_uses_the_winter_process_prefix(): void
    {
        self::assertSame('winter-process: SampleProcess', $this->invoke(new SampleProcess(), 'buildProcessTitle'));
        self::assertSame('winter-process: custom-title', $this->invoke(new TitledProcess(), 'buildProcessTitle'));
    }

    public function test_after_fork_runs_the_fork_reset_handlers(): void
    {
        $ran = false;
        ForkReset::register(static function () use (&$ran): void {
            $ran = true;
        });

        $this->invoke(new SampleProcess(), 'afterFork');

        self::assertTrue($ran);
    }

    public function test_touch_is_a_noop_for_a_bare_process(): void
    {
        // No worker slot → no heartbeat write, no error.
        $this->invoke(new SampleProcess(), 'touch');
        $this->addToAssertionCount(1);
    }

    public function test_run_is_abstract_on_the_base(): void
    {
        self::assertTrue((new \ReflectionMethod(Process::class, 'run'))->isAbstract());
    }
}
