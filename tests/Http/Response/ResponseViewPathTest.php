<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Response;

use Flytachi\Winter\Kernel\Core\KernelConfig;
use Flytachi\Winter\Kernel\Http\Response\ResponseView;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Where {@see ResponseView} looks for view files.
 *
 * Views resolve under `resources/views` without any configuration — the directory is
 * named after neither of the two roles the class distinguishes (a *template* is the
 * layout, a *resource* is the page), so it can hold both.
 */
final class ResponseViewPathTest extends TestCase
{
    private string $root = '';
    private ?string $originalRoot = null;

    protected function setUp(): void
    {
        $prop = new ReflectionProperty(KernelConfig::class, 'pathResource');
        $this->originalRoot = $prop->isInitialized() ? $prop->getValue() : null;

        $this->root = sys_get_temp_dir() . '/wk_views_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->root . '/views/layouts', 0777, true);
        @mkdir($this->root . '/views/user', 0777, true);

        file_put_contents($this->root . '/views/user/profile.php', '<p>profile</p>');
        file_put_contents($this->root . '/views/layouts/main.php', '<html><?= $content ?></html>');
        // A stray file at the resource root must NOT be reachable any more.
        file_put_contents($this->root . '/legacy.php', '<p>legacy</p>');

        KernelConfig::$pathResource = $this->root;
        ResponseView::setBasePath('');
    }

    protected function tearDown(): void
    {
        foreach (['/views/user/profile.php', '/views/layouts/main.php', '/legacy.php'] as $file) {
            @unlink($this->root . $file);
        }
        foreach (['/views/layouts', '/views/user', '/views'] as $dir) {
            @rmdir($this->root . $dir);
        }
        @rmdir($this->root);

        ResponseView::setBasePath('');
        if ($this->originalRoot !== null) {
            KernelConfig::$pathResource = $this->originalRoot;
        }
    }

    public function test_views_resolve_under_resources_views_by_default(): void
    {
        ResponseView::view('user/profile');

        self::assertSame($this->root . '/views', ResponseView::getBasePath());
    }

    public function test_a_layout_and_a_page_share_the_same_root(): void
    {
        ResponseView::render('layouts/main', 'user/profile');

        self::assertSame($this->root . '/views', ResponseView::getBasePath());
    }

    public function test_files_directly_under_resources_are_no_longer_views(): void
    {
        self::assertFileExists($this->root . '/legacy.php', 'the file is there; only the root moved');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('View resource not found');

        ResponseView::view('legacy');
    }

    public function test_a_missing_view_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('View resource not found');

        ResponseView::view('user/nope');
    }

    public function test_a_missing_layout_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('View template not found');

        ResponseView::render('layouts/nope', 'user/profile');
    }

    public function test_an_explicit_base_path_still_wins(): void
    {
        // The escape hatch for a project that keeps views somewhere else.
        ResponseView::setBasePath($this->root);

        ResponseView::view('legacy');

        self::assertSame($this->root, ResponseView::getBasePath());
    }
}
