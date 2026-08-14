<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Health;

use Composer\InstalledVersions;
use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Collector\ImplementorCollector;
use Flytachi\Winter\Kernel\Http\Header;
use Flytachi\Winter\Kernel\Ppa\Pool\PpaConnectionPool;

class HealthIndicator implements HealthIndicatorInterface
{
    private const int DEGRADED_THRESHOLD_PERCENT = 80;
    private const int DOWN_THRESHOLD_PERCENT      = 90;
    private const int DEGRADED_LATENCY_MS         = 500;

    public function health(): array
    {
        $rootDir    = Health::getRootDir();
        $components = [
            'db'     => $this->dbHealth($rootDir),
            'cache'  => $this->cacheHealth($rootDir),
            'disk'   => $this->diskHealth(),
            'memory' => $this->memoryHealth(),
        ];

        // Merge every discovered HealthContributor, keyed by its name(). A contributor
        // may override a built-in component by reusing its key.
        foreach ($this->contributors() as $name => $status) {
            $components[$name] = $status;
        }

        $statuses = array_column($components, 'status');
        $overall  = 'up';

        if (in_array('down', $statuses, true)) {
            $overall = 'down';
        } elseif (in_array('degraded', $statuses, true)) {
            $overall = 'degraded';
        }

        return ['status' => $overall, 'components' => $components];
    }

    public function info(): array
    {
        $root = InstalledVersions::getRootPackage();
        return [
            'php'       => [
                'version'      => PHP_VERSION,
                'sapi'         => PHP_SAPI,
                'zend_version' => zend_version(),
            ],
            'framework' => [
                'name'    => 'flytachi/winter-kernel',
                'version' => InstalledVersions::getPrettyVersion('flytachi/winter-kernel'),
                'runtime' => Runtime::mode()->name,
            ],
            'project'   => !empty($root) ? [
                'name'    => $root['name'] ?? '',
                'type'    => $root['type'] ?? '',
                'version' => $root['pretty_version'] ?? '',
                'isDev'   => $root['dev'] ?? false,
            ] : null,
        ];
    }

    public function metrics(): array
    {
        if (Runtime::isSwooleCoroutine()) {
            $ctx           = \Swoole\Coroutine::getContext();
            $executionTime = microtime(true) - ($ctx['__request_start'] ?? microtime(true));
            $method        = $ctx['__request_method'] ?? '';
            $uri           = $ctx['__request_uri'] ?? '';
        } else {
            $executionTime = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
            $method        = $_SERVER['REQUEST_METHOD'] ?? '';
            $uri           = $_SERVER['REQUEST_URI'] ?? '';
        }

        return [
            'cpu'            => Health::cpu(),
            'memory'         => Health::memory(),
            'disk'           => Health::disk(),
            'system'         => Health::system(),
            'php'            => [
                'version'        => PHP_VERSION,
                'sapi'           => PHP_SAPI,
                'zend_version'   => zend_version(),
                'execution_time' => round($executionTime * 1000, 3),
            ],
            'opcache'        => function_exists('opcache_get_status')
                ? opcache_get_status(false)
                : null,
            'requests'       => [
                'method'     => $method,
                'uri'        => $uri,
                'user_agent' => Header::getUserAgent() ?? '',
            ],
            'uptime_seconds' => Health::uptimeSeconds(),
        ];
    }

    public function env(): array
    {
        return [];
    }

    public function loggers(): array
    {
        $globalLevel  = env('LOG_LEVEL', '');
        $globalOutput = env('LOG_OUTPUT', 'auto');
        $globalFormat = env('LOG_FORMAT', 'line');

        $channels = [];
        foreach (['sys', 'http'] as $name) {
            $prefix   = 'LOG_' . strtoupper($name) . '_';
            $level    = env($prefix . 'LEVEL')  ?? $globalLevel;
            $output   = env($prefix . 'OUTPUT') ?? $globalOutput;
            $format   = env($prefix . 'FORMAT') ?? $globalFormat;
            $file     = env($prefix . 'FILE')   ?? env('LOG_FILE');
            $fileMax  = (int) (env($prefix . 'FILE_MAX') ?? env('LOG_FILE_MAX', 30));

            $entry = [
                'level'  => $level !== '' ? strtoupper((string) $level) : 'disabled',
                'output' => $output,
                'format' => $format,
            ];

            if ($output === 'file' || $file) {
                $root   = \Flytachi\Winter\Kernel\Kernel::$pathRoot;
                $logDir = \Flytachi\Winter\Kernel\Kernel::$pathStorageLog;
                $entry['file'] = [
                    'path'     => $file ?? (str_starts_with($logDir, $root)
                        ? ltrim(substr($logDir, strlen($root)), DIRECTORY_SEPARATOR)
                        : $logDir) . '/' . $name . '.log',
                    'max_files' => $fileMax,
                ];
            }

            $channels[$name] = $entry;
        }

        return $channels;
    }

    public function mappings(): array
    {
        return Health::getMappings();
    }

    // ── DB health (requires flytachi/winter-cdo) ──────────────────────────────

    final protected function dbHealth(string $rootDir): array
    {
        $interface = 'Flytachi\Winter\Cdo\Config\Common\DbConfigInterface';
        $details   = [];

        if ($rootDir !== '' && interface_exists($interface)) {
            $collector = new ImplementorCollector($interface);
            ClassScanner::scanner($rootDir)->collect($collector)->execute();

            foreach ($collector->getResult() as $ref) {
                /** @var \Flytachi\Winter\Cdo\Config\Common\DbConfigInterface $config */
                $config = $ref->newInstance();
                $config->setUp();

                try {
                    $result  = $config->pingDetail();
                    $latency = $result['latency'] ?? null;

                    if (!$result['status']) {
                        $status = 'down';
                    } elseif ($latency !== null && $latency >= self::DEGRADED_LATENCY_MS) {
                        $status = 'degraded';
                    } else {
                        $status = 'up';
                    }

                    $details[$ref->getName()] = [
                        'status'  => $status,
                        'driver'  => $config->getDriver(),
                        'latency' => $latency,
                        'error'   => $result['error'] ?? null,
                    ];
                } catch (\Throwable $e) {
                    $details[$ref->getName()] = [
                        'status'  => 'down',
                        'driver'  => $config->getDriver(),
                        'latency' => null,
                        'error'   => $e->getMessage(),
                    ];
                }
            }
        }

        $statuses = array_column($details, 'status');

        return [
            'status'  => match (true) {
                in_array('down', $statuses, true)     => 'down',
                in_array('degraded', $statuses, true) => 'degraded',
                default                                => 'up',
            },
            'details' => $details,
        ];
    }

    /**
     * Live connection-pool utilisation — the `/actuator/pools` endpoint.
     *
     * Kept apart from {@see health()} on purpose. Reachability and saturation answer
     * different questions and change on different timescales: a datasource is either
     * there or not, while its pool fills and drains inside a single second. Folding
     * the two together made a busy-but-healthy application report `degraded`, which
     * is the wrong signal for a probe that decides whether to keep serving traffic.
     *
     * Numbers are **per worker** — each worker owns its pools, and a borrow queues on
     * its own worker's pool. One saturated worker is a real stall even when the fleet
     * total looks roomy, so read the entries, not their sum. Only pools opened in
     * *this* worker appear: a datasource never used here has nothing to report.
     *
     * `saturated` means every connection is handed out (`active >= maximum`) and the
     * next borrow will wait for `poolWaitTimeout` before failing.
     *
     * @return array{status: string, pools: array<string, array<string, mixed>>}
     */
    public function pools(): array
    {
        $pools = [];

        foreach (PpaConnectionPool::stats() as $config => $stat) {
            $pools[$config] = $stat + [
                'saturated' => $stat['maximum'] > 0 && $stat['active'] >= $stat['maximum'],
            ];
        }

        return [
            'status' => array_any($pools, static fn(array $p): bool => $p['saturated'])
                ? 'degraded'
                : 'up',
            'pools'  => $pools,
        ];
    }

    // ── Cache health (requires flytachi/winter-cache) ─────────────────────────

    final protected function cacheHealth(string $rootDir): array
    {
        $interface = 'Flytachi\Winter\Cache\Config\Common\RedisConfigInterface';
        if ($rootDir === '' || !interface_exists($interface)) {
            return ['status' => 'up', 'details' => []];
        }

        $collector = new ImplementorCollector($interface);
        ClassScanner::scanner($rootDir)->collect($collector)->execute();
        $details     = [];
        $worstStatus = 'up';

        foreach ($collector->getResult() as $ref) {
            /** @var \Flytachi\Winter\Cache\Config\Common\RedisConfigInterface $config */
            $config = $ref->newInstance();
            $config->setUp();

            try {
                $result  = $config->pingDetail();
                $latency = $result['latency'] ?? null;

                $status = match (true) {
                    !$result['status']                                          => 'down',
                    $latency !== null && $latency >= self::DEGRADED_LATENCY_MS => 'degraded',
                    default                                                     => 'up',
                };

                if ($status === 'down' || ($status === 'degraded' && $worstStatus === 'up')) {
                    $worstStatus = $status;
                }

                $details[$ref->getName()] = [
                    'status'  => $status,
                    'latency' => $latency,
                    'error'   => $result['error'] ?? null,
                ];
            } catch (\Throwable $e) {
                $details[$ref->getName()] = [
                    'status' => 'down',
                    'error'  => $e->getMessage(),
                ];
                $worstStatus = 'down';
            }
        }

        return ['status' => $worstStatus, 'details' => $details];
    }

    // ── Disk / Memory ─────────────────────────────────────────────────────────

    private function diskHealth(): array
    {
        $info    = Health::disk();
        $percent = $info['usage_percent'];
        [$status, $warning] = $this->thresholdStatus($percent, 'Disk');

        return ['status' => $status, 'details' => array_filter([
            'free'          => $info['free'],
            'total'         => $info['total'],
            'usage_percent' => $percent,
            'warning'       => $warning,
        ])];
    }

    private function memoryHealth(): array
    {
        $info    = Health::memory();
        $limit   = $info['limit'];
        $percent = $limit > 0 ? round(($info['usage'] / $limit) * 100, 2) : 0;
        [$status, $warning] = $this->thresholdStatus($percent, 'Memory');

        return ['status' => $status, 'details' => array_filter([
            'usage'         => $info['usage'],
            'peak'          => $info['peak'],
            'limit'         => $limit,
            'usage_percent' => $percent,
            'warning'       => $warning,
        ])];
    }

    private function thresholdStatus(float $percent, string $label): array
    {
        if ($percent >= self::DOWN_THRESHOLD_PERCENT) {
            return ['down', "{$label} usage above " . self::DOWN_THRESHOLD_PERCENT . '%'];
        }
        if ($percent >= self::DEGRADED_THRESHOLD_PERCENT) {
            return ['degraded', "{$label} usage above " . self::DEGRADED_THRESHOLD_PERCENT . '%'];
        }
        return ['up', null];
    }

    // ── Custom health checks (discovered HealthContributor implementations) ────

    /**
     * Resolves every registered {@see HealthContributor} from the container and runs
     * it live. Keyed by {@see HealthContributor::name()}.
     *
     * @return array<string, array{status: string, details: array<string, mixed>}>
     */
    private function contributors(): array
    {
        $container = Container::getInstance();
        $out       = [];
        foreach (Health::getContributors() as $class) {
            /** @var HealthContributor $contributor */
            $contributor = $container->make($class);
            $out[$contributor->name()] = $contributor->check()->toArray();
        }

        return $out;
    }
}
