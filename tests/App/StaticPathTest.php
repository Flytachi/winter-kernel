<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\App;

use Flytachi\Winter\K2\App\ApplicationConfigException;
use Flytachi\Winter\K2\App\Config\ServerSettings;
use Flytachi\Winter\K2\Core\KernelConfig;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * `ServerSettings::staticPath()` — the one knob that turns static serving on.
 *
 * Serving is delegated to Swoole's native handler, so the whole job here is to
 * translate one intention into the three options Swoole expects, and to refuse a
 * directory that is not there (a typo would otherwise show up as silent 404s).
 */
final class StaticPathTest extends TestCase
{
    private string $root = '';
    private ?string $originalRoot = null;

    protected function setUp(): void
    {
        $prop = new ReflectionProperty(KernelConfig::class, 'pathRoot');
        $this->originalRoot = $prop->isInitialized() ? $prop->getValue() : null;

        $this->root = sys_get_temp_dir() . '/wk_static_cfg_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->root . '/resources/static', 0777, true);
        KernelConfig::$pathRoot = $this->root;
    }

    protected function tearDown(): void
    {
        @rmdir($this->root . '/resources/static');
        @rmdir($this->root . '/resources');
        @rmdir($this->root);

        if ($this->originalRoot !== null) {
            KernelConfig::$pathRoot = $this->originalRoot;
        }
    }

    private function settings(): ServerSettings
    {
        return ServerSettings::fromEnv();
    }

    public function test_static_is_off_until_asked_for(): void
    {
        $options = $this->settings()->toArray();

        self::assertArrayNotHasKey('document_root', $options);
        self::assertArrayNotHasKey('enable_static_handler', $options, 'an API-only service serves no files');
    }

    public function test_a_relative_path_resolves_against_the_project_root(): void
    {
        $options = $this->settings()->staticPath('resources/static')->toArray();

        self::assertSame($this->root . '/resources/static', $options['document_root']);
        self::assertTrue($options['enable_static_handler']);
    }

    public function test_an_absolute_path_is_used_as_is(): void
    {
        $options = $this->settings()->staticPath($this->root . '/resources/static')->toArray();

        self::assertSame($this->root . '/resources/static', $options['document_root']);
    }

    public function test_trailing_slashes_are_trimmed(): void
    {
        $options = $this->settings()->staticPath('resources/static/')->toArray();

        self::assertSame($this->root . '/resources/static', $options['document_root']);
    }

    public function test_locations_restrict_which_prefixes_are_treated_as_static(): void
    {
        $options = $this->settings()
            ->staticPath('resources/static', ['/assets', '/favicon.ico'])
            ->toArray();

        self::assertSame(['/assets', '/favicon.ico'], $options['static_handler_locations']);
    }

    public function test_no_locations_means_no_restriction(): void
    {
        $options = $this->settings()->staticPath('resources/static')->toArray();

        self::assertArrayNotHasKey(
            'static_handler_locations',
            $options,
            'omitting the list exposes the whole directory — Swoole checks every request',
        );
    }

    public function test_a_missing_directory_fails_the_boot(): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage('Static directory does not exist');

        $this->settings()->staticPath('resources/typo');
    }

    public function test_it_keeps_other_options_intact(): void
    {
        $options = $this->settings()
            ->workers(4)
            ->staticPath('resources/static')
            ->maxRequest(1000)
            ->toArray();

        self::assertSame(4, $options['worker_num']);
        self::assertSame(1000, $options['max_request']);
        self::assertSame($this->root . '/resources/static', $options['document_root']);
    }
}
