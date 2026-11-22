<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use PHPUnit\Framework\TestCase;

/**
 * The log levels with nothing set and with some: `sys` and `http` write `critical`
 * without a `LOG_LEVEL`, never nothing; a library's channel (`ppa`, `redis`) exists only
 * with the library and writes `info` then. A channel's own `LOG_<CH>_LEVEL` wins, and
 * without it the channel takes `LOG_LEVEL`.
 *
 * Each case boots the kernel in a fresh PHP process, as the environment is read once.
 */
final class LogDefaultsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wk_logdef_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root . '/probe.php', sprintf(<<<'PHP'
            <?php
            require %s;
            use Flytachi\Winter\Logger\LoggerFactory;
            Flytachi\Winter\Kernel\Kernel::init(pathRoot: %s);
            foreach (['sys', 'http', 'ppa', 'redis'] as $ch) {
                $log = LoggerFactory::getLogger('probe', $ch);
                $log->debug("{$ch}-debug");
                $log->info("{$ch}-info");
                $log->error("{$ch}-error");
                $log->critical("{$ch}-critical");
            }
            PHP,
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->root, true),
        ));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param array<string, string> $env */
    private function boot(array $env): string
    {
        file_put_contents($this->root . '/.env', implode('', array_map(
            static fn(string $k, string $v): string => "{$k}={$v}\n",
            array_keys($env),
            $env,
        )) . "LOG_COLOR=never\n");
        $proc = proc_open(
            [PHP_BINARY, $this->root . '/probe.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['XDEBUG_MODE' => 'off'] + array_filter(
                getenv(),
                static fn(string $k): bool => !str_starts_with($k, 'LOG_'),
                ARRAY_FILTER_USE_KEY,
            ),
        );
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), $out);
        return $out;
    }

    /** @return list<string> The probe's messages that were written, in order. */
    private function written(string $out): array
    {
        preg_match_all('/\b(?:sys|http|ppa|redis)-(?:debug|info|error|critical)\b/', $out, $m);
        return $m[0];
    }

    public function test_without_a_level_the_kernel_says_critical_and_the_libraries_info(): void
    {
        self::assertSame([
            'sys-critical',
            'http-critical',
            'ppa-info', 'ppa-error', 'ppa-critical',
            'redis-info', 'redis-error', 'redis-critical',
        ], $this->written($this->boot([])));
    }

    public function test_log_level_applies_to_every_channel(): void
    {
        self::assertSame([
            'sys-error', 'sys-critical',
            'http-error', 'http-critical',
            'ppa-error', 'ppa-critical',
            'redis-error', 'redis-critical',
        ], $this->written($this->boot(['LOG_LEVEL' => 'error'])));
    }

    public function test_a_channel_level_wins_over_log_level(): void
    {
        self::assertSame([
            'sys-critical',
            'http-info', 'http-error', 'http-critical',
            'ppa-debug', 'ppa-info', 'ppa-error', 'ppa-critical',
            'redis-critical',
        ], $this->written($this->boot([
            'LOG_LEVEL' => 'critical',
            'LOG_HTTP_LEVEL' => 'info',
            'LOG_PPA_LEVEL' => 'debug',
        ])));
    }

    public function test_a_channel_level_alone_leaves_the_others_at_their_default(): void
    {
        self::assertSame([
            'sys-debug', 'sys-info', 'sys-error', 'sys-critical',
            'http-critical',
            'ppa-info', 'ppa-error', 'ppa-critical',
            'redis-info', 'redis-error', 'redis-critical',
        ], $this->written($this->boot(['LOG_SYS_LEVEL' => 'debug'])));
    }
}
