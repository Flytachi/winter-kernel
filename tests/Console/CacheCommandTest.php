<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use Flytachi\Winter\Kernel\Cache\CacheDefinition;
use Flytachi\Winter\Kernel\Cache\CacheType;
use Flytachi\Winter\Kernel\Cache\Store\FileStore;
use Flytachi\Winter\Kernel\Core\KernelConfig;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * `call cache` against a real project in a child process.
 *
 * The command runs where an operator runs it — beside the server, in a process of
 * its own — so it can only reach caches outside the server's memory. What it prints
 * and how it exits is the contract a deploy script relies on.
 */
final class CacheCommandTest extends TestCase
{
    private string $project = '';
    private ?string $originalStorageCache = null;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/wk_cachecmd_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->project . '/storage/cache', 0777, true);
        file_put_contents($this->project . '/.env', "DEBUG=false\n");
        file_put_contents($this->project . '/bootstrap.php', sprintf(<<<'PHP'
            <?php
            require %s;

            #[\Flytachi\Winter\Kernel\App\Attribute\EnableCaching]
            final class CacheCommandApplication extends \Flytachi\Winter\Kernel\WinterApplication
            {
            }

            final class CacheCommandCaches implements \Flytachi\Winter\Kernel\Cache\CacheConfigurer
            {
                public function configureCaches(\Flytachi\Winter\Kernel\Cache\Caches $caches): void
                {
                    $caches->file('tokens', ttl: 3300)->memory('stock', maxItems: 10);
                }
            }

            CacheCommandApplication::run($argv);
            PHP, var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)));

        // The test process writes entries where the child will look for them.
        $property = new ReflectionProperty(KernelConfig::class, 'pathStorageCache');
        $this->originalStorageCache = $property->isInitialized() ? $property->getValue() : null;
        KernelConfig::$pathStorageCache = $this->project . '/storage/cache';
    }

    protected function tearDown(): void
    {
        if ($this->originalStorageCache !== null) {
            KernelConfig::$pathStorageCache = $this->originalStorageCache;
        }
        self::remove($this->project);
        self::remove(sys_get_temp_dir() . '/flytachi.winter.volatile.' . basename($this->project));
    }

    private function seed(string ...$keys): void
    {
        $store = new FileStore(new CacheDefinition('tokens', CacheType::File, 0, null, 3300));
        foreach ($keys as $key) {
            $store->put($key, serialize('value of ' . $key));
        }
    }

    /**
     * @return array{int, string} Exit code and output without colours.
     */
    private function call(string ...$arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->project . '/bootstrap.php', 'cache', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
        );
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) preg_replace('/\e\[[0-9;]*m/', '', (string) $output)];
    }

    public function test_show_lists_every_cache_and_counts_what_it_can_see(): void
    {
        $this->seed('payment', 'shipping');

        [$code, $output] = $this->call('show');

        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/tokens \.+ \[file · ttl 3300 s · 2 entries\]/', $output);
        self::assertMatchesRegularExpression('/stock \.+ \[memory · 10 items per worker · no ttl\]/', $output);
    }

    public function test_show_of_one_cache_prints_its_keys(): void
    {
        $this->seed('payment');

        [$code, $output] = $this->call('show', 'tokens');

        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/payment \.+ \[\d+ B · \d+ s left\]/', $output);
    }

    public function test_show_of_a_memory_cache_explains_why_there_are_no_keys(): void
    {
        [$code, $output] = $this->call('show', 'stock');

        self::assertSame(0, $code);
        self::assertStringContainsString('a console command cannot see its entries', $output);
    }

    public function test_clear_empties_a_file_cache(): void
    {
        $this->seed('payment', 'shipping');

        [$code, $output] = $this->call('clear', 'tokens');

        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/tokens \.+ \[CLEARED \(2 entries\)\]/', $output);
        self::assertSame(
            0,
            new FileStore(new CacheDefinition('tokens', CacheType::File, 0, null, null))->count(),
        );
    }

    public function test_clearing_what_a_console_cannot_reach_fails_loudly(): void
    {
        [$code, $output] = $this->call('clear', 'stock');

        self::assertSame(1, $code, 'a deploy script must notice');
        self::assertStringContainsString('lives in the memory of the running server', $output);
    }

    public function test_an_unknown_cache_fails_and_names_the_declared_ones(): void
    {
        [$code, $output] = $this->call('clear', 'tokenz');

        self::assertSame(1, $code);
        self::assertStringContainsString("No cache named 'tokenz'. Declared: tokens, stock.", $output);
    }

    public function test_clear_all_empties_the_file_caches_and_skips_the_rest(): void
    {
        $this->seed('payment');

        [$code, $output] = $this->call('clear', '--all');

        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/tokens \.+ \[CLEARED \(1 entries\)\]/', $output);
        self::assertMatchesRegularExpression('/stock \.+ \[SKIPPED \(memory, server memory\)\]/', $output);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
