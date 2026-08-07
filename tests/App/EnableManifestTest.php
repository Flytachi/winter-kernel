<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Attribute\EnableAsync;
use Flytachi\Winter\Kernel\App\Attribute\EnableDaemon;
use Flytachi\Winter\Kernel\App\Attribute\EnableProcess;
use Flytachi\Winter\Kernel\App\Attribute\EnableScheduler;
use Flytachi\Winter\Kernel\App\Attribute\EnableWeb;
use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The #[Enable*] → Component manifest resolution, tested as a pure decision
 * algorithm via reflection on fixture App classes (no boot, no forks).
 */
final class EnableManifestTest extends TestCase
{
    /**
     * @param class-string<WinterApplication> $app
     * @return list<Component>
     */
    private function resolve(string $app): array
    {
        return new ReflectionMethod($app, 'resolveComponents')->invoke(null);
    }

    /**
     * @param class-string<WinterApplication> $app
     * @param class-string $attribute
     */
    private function hasAttr(string $app, string $attribute): bool
    {
        return new ReflectionMethod($app, 'hasAttribute')->invoke(null, $attribute);
    }

    public function test_full_manifest_maps_every_attribute_in_order(): void
    {
        $c = $this->resolve(FullApp::class);

        self::assertCount(5, $c);
        self::assertSame(ComponentKind::Http, $c[0]->kind);
        self::assertSame(ComponentKind::Scheduler, $c[1]->kind);
        self::assertSame(Scheduler::class, $c[1]->class);
        self::assertSame(ComponentKind::Process, $c[2]->kind);
        self::assertSame(ComponentKind::Process, $c[3]->kind);
        self::assertSame(ComponentKind::Daemon, $c[4]->kind);
    }

    public function test_repeatable_process_preserves_declaration_order(): void
    {
        $c = $this->resolve(FullApp::class);

        self::assertSame('Main\\Proc\\A', $c[2]->class);
        self::assertSame('Main\\Proc\\B', $c[3]->class);
        self::assertSame('Main\\Daemon\\E', $c[4]->class);
    }

    public function test_scheduler_uses_declared_class(): void
    {
        $c = $this->resolve(CustomSchedulerApp::class);

        self::assertCount(1, $c);
        self::assertSame(ComponentKind::Scheduler, $c[0]->kind);
        self::assertSame('Custom\\Sched', $c[0]->class);
    }

    public function test_headless_app_has_no_http(): void
    {
        $c = $this->resolve(HeadlessApp::class);

        self::assertCount(1, $c);
        self::assertSame(ComponentKind::Process, $c[0]->kind);
        self::assertSame('Main\\Proc\\Only', $c[0]->class);
    }

    public function test_empty_app_yields_empty_manifest(): void
    {
        self::assertSame([], $this->resolve(EmptyApp::class));
    }

    public function test_enable_async_is_detected(): void
    {
        self::assertTrue($this->hasAttr(FullApp::class, EnableAsync::class));
        self::assertFalse($this->hasAttr(HeadlessApp::class, EnableAsync::class));
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

#[EnableWeb]
#[EnableAsync]
#[EnableScheduler]
#[EnableProcess('Main\\Proc\\A')]
#[EnableProcess('Main\\Proc\\B')]
#[EnableDaemon('Main\\Daemon\\E')]
final class FullApp extends WinterApplication
{
}

#[EnableScheduler('Custom\\Sched')]
final class CustomSchedulerApp extends WinterApplication
{
}

#[EnableProcess('Main\\Proc\\Only')]
final class HeadlessApp extends WinterApplication
{
}

final class EmptyApp extends WinterApplication
{
}
