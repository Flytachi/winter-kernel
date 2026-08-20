<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Attribute\EnableActuator;
use Flytachi\Winter\Kernel\App\Attribute\EnableAsync;
use Flytachi\Winter\Kernel\App\Attribute\EnableWeb;
use Flytachi\Winter\Kernel\App\Attribute\Import;
use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A manifest attribute left on a base class.
 *
 * PHP does not inherit attributes, so `#[EnableWeb]` on an abstract base reads as zero
 * attributes on the class extending it. That is kept deliberately — the manifest is
 * worth having because one class tells you everything the process will start, and
 * inheritance would mean walking a hierarchy to find out. What is not kept is the
 * silence: a base carrying `#[EnableActuator]` while the child carries `#[EnableWeb]`
 * used to start an application with no actuator and no explanation anywhere.
 */
final class InheritedManifestTest extends TestCase
{
    /** @param class-string<WinterApplication> $app */
    private function check(string $app): void
    {
        new ReflectionMethod($app, 'assertManifestIsNotInherited')->invoke(null);
    }

    public function test_a_manifest_on_the_class_itself_is_fine(): void
    {
        $this->check(OwnManifestApp::class);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_plain_application_is_fine(): void
    {
        $this->check(BareApp::class);

        $this->expectNotToPerformAssertions();
    }

    public function test_an_attribute_on_the_parent_is_refused(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('#[EnableWeb] is declared on');
        $this->expectExceptionMessage('PHP does not inherit attributes');

        $this->check(InheritsWebApp::class);
    }

    /** The expensive case: the child looks configured, so nothing else complains. */
    public function test_a_partial_loss_is_refused_even_though_the_app_would_start(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('#[EnableActuator]');

        $this->check(WebOnChildActuatorOnParentApp::class);
    }

    public function test_the_message_names_both_classes(): void
    {
        try {
            $this->check(InheritsWebApp::class);
            self::fail('should have been refused');
        } catch (ApplicationConfigException $e) {
            self::assertStringContainsString(WebBaseApp::class, $e->getMessage(), 'where it is');
            self::assertStringContainsString(InheritsWebApp::class, $e->getMessage(), 'where it should be');
        }
    }

    public function test_an_attribute_two_levels_up_is_still_found(): void
    {
        $this->expectException(ApplicationConfigException::class);

        $this->check(GrandchildApp::class);
    }

    public function test_async_counts_too(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('#[EnableAsync]');

        $this->check(InheritsAsyncApp::class);
    }

    /** Imports are part of the manifest: an inherited one would import nothing, quietly. */
    public function test_import_counts_too(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('#[Import]');

        $this->check(InheritsImportApp::class);
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

#[EnableWeb]
final class OwnManifestApp extends WinterApplication
{
}

final class BareApp extends WinterApplication
{
}

#[EnableWeb]
abstract class WebBaseApp extends WinterApplication
{
}

final class InheritsWebApp extends WebBaseApp
{
}

abstract class MiddleApp extends WebBaseApp
{
}

final class GrandchildApp extends MiddleApp
{
}

#[EnableActuator]
abstract class ActuatorBaseApp extends WinterApplication
{
}

#[EnableWeb]
final class WebOnChildActuatorOnParentApp extends ActuatorBaseApp
{
}

#[EnableAsync]
abstract class AsyncBaseApp extends WinterApplication
{
}

final class InheritsAsyncApp extends AsyncBaseApp
{
}

#[Import('acme/whatever')]
abstract class ImportBaseApp extends WinterApplication
{
}

final class InheritsImportApp extends ImportBaseApp
{
}
