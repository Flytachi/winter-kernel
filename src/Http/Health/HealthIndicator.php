<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Health;

use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;
use Composer\InstalledVersions;
use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Collector\ImplementorCollector;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\Kernel\Http\Header;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Redis\RedisPool;

/**
 * The built-in `/actuator/*` report.
 *
 * `health()` aggregates the built-in components — database and Redis when the
 * application has them, disk and memory always — plus every discovered
 * {@see HealthContributor}; the worst component decides the overall status. The
 * remaining methods are plain reads of runtime state.
 *
 * The thresholds are constants read through `static::`: a subclass overrides one to
 * move it.
 *
 * Extend it to change one endpoint and inherit the rest: a public method's name is
 * its endpoint, so adding `queues()` publishes `/actuator/queues`.
 *
 * @link https://winterframe.net/docs/actuator Endpoints, thresholds and response codes
 */
class HealthIndicator implements HealthIndicatorInterface
{
    /** Latency of a database or Redis answer, in ms, from which it counts as slow. */
    protected const int DEGRADED_LATENCY_MS = 500;

    /** Disk use, in %, from which the disk is `degraded`: filling up, still writable. */
    protected const int DISK_DEGRADED_PERCENT = 90;

    /**
     * Free space, in bytes, below which the disk is `down`. Spring's own default: 10 MB is
     * where writing logs, cache and uploads starts to fail — the application is about to
     * stop working, not merely filling up. A percentage cannot say that: 10 % of a 2 TB
     * volume is 200 GB.
     */
    protected const int DISK_DOWN_FREE_BYTES = 10 * 1024 * 1024;

    /**
     * Worker memory, in % of its limit, from which memory is `degraded`. Never `down`:
     * the figure is of the one worker that answered the probe, and a 503 would take the
     * whole instance out of rotation over it — while its other workers are fine and this
     * one is replaced on its own at its next recycle.
     */
    protected const int MEMORY_DEGRADED_PERCENT = 90;

    /**
     * Seconds a database or Redis check stands before it is run again, per worker. A
     * probe every few seconds used to open a new connection to every datasource each
     * time — two databases and a 5-second probe are 24 connections a minute per instance.
     */
    protected const int CHECK_CACHE_SECONDS = 5;

    /** @var array<string, array{at: float, result: array}> Checks per component, per worker. */
    private static array $checked = [];

    public function health(): array
    {
        $rootDir    = Health::getRootDir();
        $components = [];
        // A datasource the application does not have is not reported: `up` with nothing
        // checked said something that was not true.
        $db = self::cached('db', fn(): array => $this->dbHealth($rootDir));
        if ($db['details'] !== []) {
            $components['db'] = $db;
        }
        $redis = self::cached('redis', fn(): array => $this->redisHealth($rootDir));
        if ($redis['details'] !== []) {
            $components['redis'] = $redis;
        }
        $components['disk']   = $this->diskHealth();
        $components['memory'] = $this->memoryHealth();

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
            // The path only, as the Swoole branch records it — the query string may carry
            // personal data and has no place in metrics.
            $uri           = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? ''), 2)[0];
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

    /**
     * The project scanner used by the component checks, sharing the boot-time class
     * list instead of walking the tree again.
     *
     * Unlike the boot scan, this one runs **per request**: a probe hits the endpoint
     * every few seconds, and every hit used to re-read every `.php` in the project —
     * twice, once per component. Measured on 304 files that is ~25 ms per walk even
     * when everything is already in memory, which is why `health` was an order of
     * magnitude slower than the other endpoints.
     *
     * The cache is the one the application boots from, so a hit costs a `require` of
     * a class-name list and no filesystem work at all. `DEBUG=true` keeps the live
     * walk, exactly as it does everywhere else — a new datasource must show up in the
     * report without a rebuild.
     */
    private static function scanner(string $rootDir): Scanner
    {
        return ClassScanner::scanner(
            rootDir: $rootDir,
            cache: env('DEBUG', false) ? null : Kernel::$pathStorageVolatile . '/di.php',
        );
    }

    final protected function dbHealth(string $rootDir): array
    {
        $interface = 'Flytachi\Winter\Cdo\Config\Common\DbConfigInterface';
        $details   = [];

        if ($rootDir !== '' && interface_exists($interface)) {
            $collector = new ImplementorCollector($interface);
            self::scanner($rootDir)->collect($collector)->execute();

            foreach ($collector->getResult() as $ref) {
                // Inside the try with the ping: a configuration that cannot even be set up
                // is that datasource down, not a 500 for the whole report.
                $config = null;
                try {
                    /** @var \Flytachi\Winter\Cdo\Config\Common\DbConfigInterface $config */
                    $config = $ref->newInstance();
                    $config->setUp();
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
                        'status'     => $status,
                        'driver'     => $config->getDriver(),
                        'latency'    => $latency,
                        'error'      => self::connectionError($ref->getName(), $result['error'] ?? null),
                        'checked_at' => date(DATE_ATOM),
                    ];
                } catch (\Throwable $e) {
                    $details[$ref->getName()] = [
                        'status'     => 'down',
                        'driver'     => self::driverOf($config),
                        'latency'    => null,
                        'error'      => self::connectionError($ref->getName(), $e->getMessage()),
                        'checked_at' => date(DATE_ATOM),
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

        // Both layers are optional and both report the same shape, keyed by the config
        // class — which is unique, so the two merge without collision. `source` is kept
        // so a reader can tell a database pool from a Redis one without knowing the
        // application's class names.
        $sources = [];
        if (DepSupport::has(Dep::Ppa)) {
            $sources['ppa'] = PpaConnectionPool::stats();
        }
        if (DepSupport::has(Dep::Redis)) {
            $sources['redis'] = RedisPool::stats();
        }

        foreach ($sources as $source => $stats) {
            foreach ($stats as $config => $stat) {
                $pools[$config] = $stat + [
                    'saturated' => $stat['maximum'] > 0 && $stat['active'] >= $stat['maximum'],
                    'source'    => $source,
                ];
            }
        }

        return [
            'status' => array_any($pools, static fn(array $p): bool => $p['saturated'])
                ? 'degraded'
                : 'up',
            'pools'  => $pools,
        ];
    }

    // ── Redis health (requires flytachi/winter-redis) ─────────────────────────

    final protected function redisHealth(string $rootDir): array
    {
        if ($rootDir === '' || !DepSupport::has(Dep::Redis)) {
            return ['status' => 'up', 'details' => []];
        }

        $interface = 'Flytachi\Winter\Redis\Config\Common\RedisConfigInterface';

        $collector = new ImplementorCollector($interface);
        self::scanner($rootDir)->collect($collector)->execute();
        $details     = [];
        $worstStatus = 'up';

        foreach ($collector->getResult() as $ref) {
            try {
                /** @var \Flytachi\Winter\Redis\Config\Common\RedisConfigInterface $config */
                $config = $ref->newInstance();
                $config->setUp();
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
                    'status'     => $status,
                    'latency'    => $latency,
                    'error'      => self::connectionError($ref->getName(), $result['error'] ?? null),
                    'checked_at' => date(DATE_ATOM),
                ];
            } catch (\Throwable $e) {
                $details[$ref->getName()] = [
                    'status'     => 'down',
                    'error'      => self::connectionError($ref->getName(), $e->getMessage()),
                    'checked_at' => date(DATE_ATOM),
                ];
                $worstStatus = 'down';
            }
        }

        return ['status' => $worstStatus, 'details' => $details];
    }

    // ── Disk / Memory ─────────────────────────────────────────────────────────

    /**
     * The disk the application writes to — its storage: logs, cache, uploads. The root
     * of the machine, measured before, can be another volume entirely in a container.
     */
    private function diskHealth(): array
    {
        $path    = isset(Kernel::$pathStorage) && is_dir(Kernel::$pathStorage) ? Kernel::$pathStorage : '/';
        $info    = Health::disk($path);
        $percent = $info['usage_percent'];
        [$status, $warning] = match (true) {
            $info['free'] < static::DISK_DOWN_FREE_BYTES => [
                'down',
                'Less than ' . self::megabytes(static::DISK_DOWN_FREE_BYTES) . ' free',
            ],
            $percent >= static::DISK_DEGRADED_PERCENT => [
                'degraded',
                'Disk usage above ' . static::DISK_DEGRADED_PERCENT . '%',
            ],
            default => ['up', null],
        };

        return ['status' => $status, 'details' => array_filter([
            'path'          => $path,
            'free'          => $info['free'],
            'total'         => $info['total'],
            'usage_percent' => $percent,
            'warning'       => $warning,
        ], static fn($value): bool => $value !== null)];
    }

    private function memoryHealth(): array
    {
        $info    = Health::memory();
        $limit   = $info['limit'];
        $percent = $limit > 0 ? round(($info['usage'] / $limit) * 100, 2) : 0;
        $high    = $percent >= static::MEMORY_DEGRADED_PERCENT;

        return ['status' => $high ? 'degraded' : 'up', 'details' => array_filter([
            'usage'         => $info['usage'],
            'peak'          => $info['peak'],
            'limit'         => $limit,
            'usage_percent' => $percent,
            'warning'       => $high ? 'Memory usage above ' . static::MEMORY_DEGRADED_PERCENT . '%' : null,
        ], static fn($value): bool => $value !== null)];
    }

    /**
     * A component's check as it was run within the last {@see CHECK_CACHE_SECONDS}, or
     * run now.
     *
     * @param \Closure(): array $check
     */
    private static function cached(string $component, \Closure $check): array
    {
        $now = microtime(true);
        $hit = self::$checked[$component] ?? null;
        if ($hit !== null && $now - $hit['at'] < static::CHECK_CACHE_SECONDS) {
            return $hit['result'];
        }
        $result = $check();
        self::$checked[$component] = ['at' => $now, 'result' => $result];
        return $result;
    }

    /**
     * What the report says about a failed connection. The full message goes to the log:
     * it may name the host, the user or the database, and the endpoint is open unless
     * guarded. With `DEBUG=true` the report carries it too, as error responses do.
     */
    private static function connectionError(string $config, ?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }
        LoggerFactory::getLogger(static::class)->warning("Health check of {$config} failed: {$message}");
        return env('DEBUG', false) ? $message : 'connection failed';
    }

    /** The driver a configuration names, or null when it is broken before it could say. */
    private static function driverOf(?object $config): ?string
    {
        try {
            return $config !== null && method_exists($config, 'getDriver') ? (string) $config->getDriver() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function megabytes(int $bytes): string
    {
        return round($bytes / 1048576) . ' MB';
    }

    // ── Custom health checks (discovered HealthContributor implementations) ────

    /**
     * Resolves every registered {@see HealthContributor} from the container and runs
     * it live. Keyed by {@see HealthContributor::name()}.
     *
     * A check that throws is that component `down` — not the whole report. An escaping
     * exception used to turn `/actuator/health` into a 500: a probe saw an error page
     * instead of which component failed, and every other component's state was lost.
     *
     * The exception itself goes to the log. The report says only `check failed`: the
     * endpoint is open unless guarded, and an exception's message may carry a host, a
     * user or a path. With `DEBUG=true` the message and the class are in the details,
     * as error responses carry their trace only then.
     *
     * @return array<string, array{status: string, details: array<string, mixed>}>
     */
    private function contributors(): array
    {
        $container = Container::getInstance();
        $out       = [];
        foreach (Health::getContributors() as $class) {
            // The class's short name stands in when the check cannot even say its own.
            $name = substr((string) strrchr('\\' . $class, '\\'), 1);
            try {
                /** @var HealthContributor $contributor */
                $contributor = $container->make($class);
                $name = $contributor->name();
                $out[$name] = $contributor->check()->toArray();
            } catch (\Throwable $e) {
                LoggerFactory::getLogger(static::class)->warning(
                    "Health check {$name} failed: {$e->getMessage()}",
                    ['contributor' => $class, 'exception' => $e::class],
                );
                $status = HealthStatus::down();
                if (env('DEBUG', false)) {
                    $status->withDetail('error', $e->getMessage())->withDetail('exception', $e::class);
                } else {
                    $status->withDetail('error', 'check failed');
                }
                $out[$name] = $status->toArray();
            }
        }

        return $out;
    }
}
