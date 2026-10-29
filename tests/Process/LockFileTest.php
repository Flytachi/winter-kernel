<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process;

use Flytachi\Winter\Kernel\Process\Internal\LockFile;
use PHPUnit\Framework\TestCase;

/**
 * The lock behind "one process per class" and "one run per application".
 *
 * Its folder is `.winter/runnable`, and a fresh project does not have it yet. Without
 * the folder the lock file could not be made, and the lock quietly held nothing: the
 * first run went unguarded and a second one was not refused.
 */
final class LockFileTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wk_lock_' . getmypid() . '_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_a_lock_in_a_folder_not_made_yet_still_holds(): void
    {
        $path = $this->dir . '/.winter/runnable/App.lock';
        self::assertDirectoryDoesNotExist(dirname($path));

        $lock = LockFile::take($path);

        self::assertNotNull($lock);
        self::assertTrue($lock->reliable, 'the folder was made and the lock really holds');
        self::assertTrue(LockFile::probe($path), 'another reader sees it held');
    }

    public function test_a_second_taker_is_refused(): void
    {
        $path = $this->dir . '/.winter/runnable/App.lock';
        $first = LockFile::take($path);

        $second = LockFile::take($path);

        self::assertNotNull($first);
        self::assertNull($second);
    }
}
