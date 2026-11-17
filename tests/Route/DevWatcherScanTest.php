<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Dev\DevWatcher;
use PHPUnit\Framework\TestCase;

/** What the dev watcher counts as the source: `.php` files, nothing a tool leaves beside them. */
final class DevWatcherScanTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wk_watch_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/main', 0777, true);
        mkdir($this->dir . '/frontend', 0777, true);
        file_put_contents($this->dir . '/main/A.php', '<?php');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array<string, int> */
    private function scan(): array
    {
        $watcher = new DevWatcher([$this->dir]);
        return (fn(): array => $this->scan())->call($watcher);
    }

    public function test_a_link_to_a_folder_is_not_a_source_file(): void
    {
        // pnpm's store, put in the project when the home is on another filesystem: a link
        // per project, rewritten on every install.
        mkdir($this->dir . '/.pnpm-store/v11/projects', 0777, true);
        symlink($this->dir . '/frontend', $this->dir . '/.pnpm-store/v11/projects/9d3db11b');
        symlink($this->dir . '/nowhere', $this->dir . '/main/broken');

        self::assertSame([$this->dir . '/main/A.php'], array_keys($this->scan()));
    }

    public function test_an_empty_folder_is_not_a_source_file(): void
    {
        mkdir($this->dir . '/main/empty');

        self::assertSame([$this->dir . '/main/A.php'], array_keys($this->scan()));
    }
}
