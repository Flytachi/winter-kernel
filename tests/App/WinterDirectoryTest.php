<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * `.winter/` cannot be written — typically a project folder owned by root, mounted into a
 * container that runs as `winter`. The start used to fail far from the cause (`di build`:
 * "cache file was not produced"; the application came up and lost its components one by
 * one); it now stops at once, naming the folder and the user.
 */
final class WinterDirectoryTest extends TestCase
{
    private string $root = '';
    private ?string $previous = null;

    protected function setUp(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes anywhere.');
        }
        $this->root = sys_get_temp_dir() . '/wk_winterdir_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        // Unset until a kernel was booted in this process: left as it was afterwards.
        $this->previous = (new \ReflectionProperty(Kernel::class, 'pathWinter'))->isInitialized() ? Kernel::$pathWinter : null;
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            Kernel::$pathWinter = $this->previous;
        }
        @chmod($this->root, 0777);
        @chmod($this->root . '/.winter', 0777);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_it_is_made_when_the_project_can_be_written(): void
    {
        Kernel::$pathWinter = $this->root . '/.winter';

        Kernel::assertWinterDirectory();

        self::assertDirectoryExists($this->root . '/.winter');
    }

    public function test_a_project_folder_that_cannot_be_written_is_named(): void
    {
        Kernel::$pathWinter = $this->root . '/.winter';
        chmod($this->root, 0555);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("The framework cannot write {$this->root}/.winter as this user");

        Kernel::assertWinterDirectory();
    }

    public function test_an_existing_folder_that_cannot_be_written_is_named(): void
    {
        mkdir($this->root . '/.winter');
        chmod($this->root . '/.winter', 0555);
        Kernel::$pathWinter = $this->root . '/.winter';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('chown -R');

        Kernel::assertWinterDirectory();
    }
}
