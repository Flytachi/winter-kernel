<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Attribute\EnableScheduler;
use Flytachi\Winter\Kernel\App\Attribute\EnableWeb;
use Flytachi\Winter\Kernel\App\Attribute\Import;
use Flytachi\Winter\Kernel\App\ContributionCollector;
use Flytachi\Winter\Kernel\Plugin;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

/**
 * What the application says about the packages it imported.
 *
 * The half worth having is the one that did not arrive: `required: false` is a deliberate
 * "carry on without it", and carrying on used to look exactly like having it — the same
 * quiet start, minus a feature nobody mentioned. The half that did arrive is worth saying
 * too, because "is this package actually wired in?" was otherwise answered by reading the
 * bootstrap file and hoping.
 */
final class ImportReportTest extends TestCase
{
    private const string REAL_PACKAGE = 'flytachi/winter-base';
    private const string FAKE_PACKAGE = 'acme/not-installed-anywhere';

    protected function setUp(): void
    {
        Plugin::forget();
    }

    protected function tearDown(): void
    {
        Plugin::forget();
    }

    /**
     * @param class-string<WinterApplication> $app
     * @return list<array{package: string, prefix: string|null, imported: bool}>
     */
    private function importsOf(string $app): array
    {
        return new ReflectionMethod($app, 'applyImports')->invoke(null);
    }

    /**
     * Seeds the factory's own cache with a recorder, so the report writes into it rather
     * than into a real channel. The factory has no injection point; the cache is keyed
     * `channel:class` and is the injection point in practice.
     *
     * @param list<array{package: string, prefix: string|null, imported: bool}> $outcomes
     */
    private function report(array $outcomes, array $contributed = [], string $app = ReportingApp::class): ImportReportLogger
    {
        $logger  = new ImportReportLogger();
        $cache   = new \ReflectionProperty(LoggerFactory::class, 'cache');
        $channel = new \ReflectionProperty(LoggerFactory::class, 'defaultChannel')->getValue();
        $original = $cache->getValue();

        $cache->setValue(null, [$channel . ':' . $app => $logger] + $original);
        try {
            new ReflectionMethod($app, 'reportImports')->invoke(null, $outcomes, $contributed);
        } finally {
            $cache->setValue(null, $original);
        }

        return $logger;
    }

    /** A counter pre-loaded with what a package supposedly brought. */
    private function brought(int $controllers = 0, int $scheduled = 0): ContributionCollector
    {
        $collector = new ContributionCollector();
        new \ReflectionProperty(ContributionCollector::class, 'controllers')->setValue($collector, $controllers);
        new \ReflectionProperty(ContributionCollector::class, 'scheduled')->setValue($collector, $scheduled);

        return $collector;
    }

    // ── What applyImports() reports ───────────────────────────────────────────

    public function test_an_installed_package_is_reported_as_imported(): void
    {
        $outcomes = $this->importsOf(ReportingApp::class);

        self::assertSame(
            [['package' => self::REAL_PACKAGE, 'prefix' => '/base', 'imported' => true]],
            $outcomes,
        );
    }

    public function test_a_package_without_a_prefix_reports_a_null_prefix(): void
    {
        $outcomes = $this->importsOf(NoPrefixApp::class);

        self::assertNull($outcomes[0]['prefix']);
        self::assertTrue($outcomes[0]['imported']);
    }

    public function test_a_missing_optional_package_is_reported_as_absent(): void
    {
        $outcomes = $this->importsOf(OptionalMissingApp::class);

        self::assertSame(
            [['package' => self::FAKE_PACKAGE, 'prefix' => null, 'imported' => false]],
            $outcomes,
        );
    }

    public function test_declaration_order_is_kept(): void
    {
        $outcomes = $this->importsOf(TwoImportsApp::class);

        self::assertSame(
            [self::REAL_PACKAGE, 'flytachi/winter-di'],
            array_column($outcomes, 'package'),
        );
    }

    public function test_an_application_with_no_imports_reports_nothing(): void
    {
        self::assertSame([], $this->importsOf(NoImportsApp::class));
    }

    // ── What reaches the log ──────────────────────────────────────────────────

    public function test_a_mounted_package_is_announced_with_its_prefix(): void
    {
        $logger = $this->report([['package' => 'acme/billing', 'prefix' => '/billing', 'imported' => true]]);

        self::assertCount(1, $logger->records);
        self::assertSame('notice', $logger->records[0]['level']);
        self::assertStringContainsString('acme/billing imported', $logger->records[0]['message']);
        self::assertStringContainsString('/billing', $logger->records[0]['message']);
    }

    /** Saying "mounted under" of a package that mounts nothing would be a small lie. */
    public function test_a_package_without_routes_is_announced_as_such(): void
    {
        $logger = $this->report([['package' => 'acme/toolkit', 'prefix' => null, 'imported' => true]]);

        self::assertSame('notice', $logger->records[0]['level']);
        self::assertStringContainsString('no routes', $logger->records[0]['message']);
    }

    public function test_a_missing_optional_package_warns(): void
    {
        $logger = $this->report([['package' => 'acme/analytics', 'prefix' => null, 'imported' => false]]);

        self::assertSame('warning', $logger->records[0]['level'], 'an import that did not happen is not routine');
        self::assertStringContainsString('not installed', $logger->records[0]['message']);
    }

    public function test_the_package_name_is_in_the_context_too(): void
    {
        $logger = $this->report([['package' => 'acme/billing', 'prefix' => '/billing', 'imported' => true]]);

        self::assertSame('acme/billing', $logger->records[0]['context']['package']);
        self::assertSame('/billing', $logger->records[0]['context']['prefix']);
    }

    public function test_nothing_imported_means_nothing_logged(): void
    {
        self::assertSame([], $this->report([])->records);
    }

    // ── Contributions that will never run ─────────────────────────────────────

    /** @return array{package: string, prefix: string|null, imported: bool} */
    private static function imported(?string $prefix): array
    {
        return ['package' => 'acme/billing', 'prefix' => $prefix, 'imported' => true];
    }

    public function test_controllers_without_a_prefix_are_named(): void
    {
        $logger = $this->report([self::imported(null)], ['acme/billing' => $this->brought(controllers: 3)]);

        self::assertSame('warning', $logger->records[1]['level']);
        self::assertStringContainsString('3 controller(s)', $logger->records[1]['message']);
        self::assertStringContainsString('without a prefix', $logger->records[1]['message']);
    }

    public function test_controllers_with_a_prefix_but_no_web_are_named(): void
    {
        $logger = $this->report(
            [self::imported('/billing')],
            ['acme/billing' => $this->brought(controllers: 2)],
            ReportHeadlessApp::class,
        );

        self::assertStringContainsString('#[EnableWeb]', $logger->records[1]['message']);
    }

    /** The application serves HTTP, so mounted routes are not a problem worth mentioning. */
    public function test_controllers_are_silent_when_the_application_serves_them(): void
    {
        $logger = $this->report(
            [self::imported('/billing')],
            ['acme/billing' => $this->brought(controllers: 2)],
            ReportWebApp::class,
        );

        self::assertCount(1, $logger->records, 'only the import notice');
    }

    public function test_scheduled_methods_without_a_scheduler_are_named(): void
    {
        $logger = $this->report(
            [self::imported('/billing')],
            ['acme/billing' => $this->brought(scheduled: 4)],
            ReportWebApp::class,
        );

        self::assertSame('warning', $logger->records[1]['level']);
        self::assertStringContainsString('4 #[Scheduled] method(s)', $logger->records[1]['message']);
        self::assertStringContainsString('#[EnableScheduler]', $logger->records[1]['message']);
    }

    public function test_scheduled_methods_are_silent_when_the_scheduler_is_on(): void
    {
        $logger = $this->report(
            [self::imported('/billing')],
            ['acme/billing' => $this->brought(scheduled: 4)],
            ReportSchedulerApp::class,
        );

        self::assertCount(1, $logger->records);
    }

    public function test_a_package_that_brought_nothing_switchable_is_silent(): void
    {
        $logger = $this->report([self::imported('/billing')], ['acme/billing' => $this->brought()]);

        self::assertCount(1, $logger->records);
    }

    /** Two dead ends in one package produce two lines, not one vague sentence. */
    public function test_both_kinds_are_reported_separately(): void
    {
        $logger = $this->report(
            [self::imported(null)],
            ['acme/billing' => $this->brought(controllers: 1, scheduled: 1)],
            ReportHeadlessApp::class,
        );

        self::assertCount(3, $logger->records);
        self::assertSame(['notice', 'warning', 'warning'], array_column($logger->records, 'level'));
    }

    public function test_every_outcome_gets_its_own_line(): void
    {
        $logger = $this->report([
            ['package' => 'acme/billing',   'prefix' => '/billing', 'imported' => true],
            ['package' => 'acme/toolkit',   'prefix' => null,       'imported' => true],
            ['package' => 'acme/analytics', 'prefix' => null,       'imported' => false],
        ]);

        self::assertSame(['notice', 'notice', 'warning'], array_column($logger->records, 'level'));
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

#[Import('flytachi/winter-base', '/base')]
final class ReportingApp extends WinterApplication
{
}

#[Import('flytachi/winter-base')]
final class NoPrefixApp extends WinterApplication
{
}

#[Import('acme/not-installed-anywhere', required: false)]
final class OptionalMissingApp extends WinterApplication
{
}

#[Import('flytachi/winter-base', '/base')]
#[Import('flytachi/winter-di', '/di')]
final class TwoImportsApp extends WinterApplication
{
}

final class NoImportsApp extends WinterApplication
{
}

final class ReportHeadlessApp extends WinterApplication
{
}

#[EnableWeb]
final class ReportWebApp extends WinterApplication
{
}

#[EnableWeb]
#[EnableScheduler]
final class ReportSchedulerApp extends WinterApplication
{
}

final class ImportReportLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
