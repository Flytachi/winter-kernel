<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use Flytachi\Winter\Console\Command\Application;
use PHPUnit\Framework\TestCase;

/**
 * `call application health`: what makes an application healthy, degraded or unhealthy
 * from what its supervisor published, and the exit code a container's check reads.
 */
final class ApplicationHealthTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    /** @return array<string, mixed> */
    private static function unit(string $name, string $state, array $more = []): array
    {
        return ['name' => $name, 'state' => $state, 'critical' => true, 'restarts' => 0, 'retries' => 3,
            'retryAt' => 0, 'adopted' => false, ...$more];
    }

    /** @param list<array<string, mixed>> $units */
    private static function verdict(array $units, int $seenAgo = 2, bool $running = true): array
    {
        return Application::verdict($running, false, self::NOW - $seenAgo, $units, self::NOW);
    }

    public function test_every_critical_component_up_is_healthy(): void
    {
        self::assertSame([0, 'healthy: 4 component(s) up'], self::verdict([
            self::unit('postgres', 'Running'),
            self::unit('Http', 'Running'),
            self::unit('import', 'Completed'),
            self::unit('Worker', 'Held'),
        ]));
    }

    public function test_a_non_critical_failure_or_a_stop_on_request_is_degraded_not_down(): void
    {
        self::assertSame([0, 'healthy, degraded: report failed, Worker stopped'], self::verdict([
            self::unit('Http', 'Running'),
            self::unit('report', 'Failed', ['critical' => false]),
            self::unit('Worker', 'Stopped'),
        ]));
    }

    public function test_a_critical_component_not_up_is_unhealthy(): void
    {
        self::assertSame([1, 'unhealthy: postgres restarting (2 of 3), Http starting'], self::verdict([
            self::unit('postgres', 'Waiting', ['restarts' => 2, 'retryAt' => self::NOW + 4]),
            self::unit('Http', 'Starting'),
            self::unit('redis', 'Running'),
        ]));
        self::assertSame([0, 'healthy, degraded: cache starting'], self::verdict([
            self::unit('Http', 'Running'),
            self::unit('cache', 'Starting', ['critical' => false]),
        ]), 'a non-critical one starting does not make it unhealthy');
    }

    public function test_a_silent_supervisor_is_unhealthy_whatever_it_said_last(): void
    {
        self::assertSame(
            [1, 'unhealthy: no report from the supervisor for 45s'],
            self::verdict([self::unit('Http', 'Running')], seenAgo: 45),
        );
    }

    public function test_not_running_or_not_reported_yet_is_unhealthy(): void
    {
        self::assertSame([1, 'unhealthy: not running'], self::verdict([], running: false));
        self::assertSame(
            [1, 'unhealthy: it runs in another environment — check it there'],
            Application::verdict(false, true, self::NOW, [], self::NOW),
        );
        self::assertSame([1, 'unhealthy: starting, no report yet'], self::verdict([]));
    }

    public function test_components_started_on_request_are_left_out(): void
    {
        self::assertSame([0, 'healthy: 1 component(s) up'], self::verdict([
            self::unit('Http', 'Running'),
            self::unit('Export', 'Failed', ['adopted' => true, 'critical' => false]),
        ]));
    }

    public function test_the_command_exits_1_with_one_line_when_the_application_does_not_run(): void
    {
        $project = sys_get_temp_dir() . '/wk_health_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($project, 0777, true);
        file_put_contents($project . '/composer.json', '{"autoload":{"psr-4":{"Main\\\\":"main/"}}}');
        file_put_contents($project . '/.env', "DEBUG=true\n");
        file_put_contents($project . '/bootstrap.php', sprintf(<<<'PHP'
            <?php
            require %s;
            #[\Flytachi\Winter\Kernel\App\Attribute\EnableHttp]
            final class HealthApplication extends \Flytachi\Winter\Kernel\WinterApplication {}
            HealthApplication::run($argv);
            PHP, var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)));
        try {
            $process = proc_open([PHP_BINARY, $project . '/bootstrap.php', 'app', 'health'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
        } finally {
            exec('rm -rf ' . escapeshellarg($project));
        }

        self::assertSame(1, $code);
        self::assertSame('', $out, 'nothing on stdout when unhealthy');
        self::assertSame("unhealthy: not running\n", $err, 'one line, nothing around it');
    }
}
