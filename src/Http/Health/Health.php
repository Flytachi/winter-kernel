<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Health;

use Flytachi\Winter\K2\Stereotype\Middleware;

final class Health
{
    private static ?array $config   = null;
    private static string $rootDir  = '';
    private static array $mappings = [];
    /** @var list<class-string<HealthContributor>> */
    private static array $contributors = [];

    private function __construct()
    {
    }

    /**
     * Enable the /actuator/* endpoints. Called once in bootstrap.php.
     *
     * @param class-string<HealthIndicatorInterface> $indicator
     * @param class-string<Middleware>|null          $middleware  Optional guard middleware.
     */
    public static function configure(
        string $indicator = HealthIndicator::class,
        ?string $middleware = null,
    ): void {
        self::$config = ['indicator' => $indicator, 'middleware' => $middleware];
    }

    public static function getConfig(): ?array
    {
        return self::$config;
    }

    public static function setRootDir(string $rootDir): void
    {
        self::$rootDir = $rootDir;
    }

    public static function getRootDir(): string
    {
        return self::$rootDir;
    }

    public static function setMappings(array $mappings): void
    {
        self::$mappings = $mappings;
    }

    public static function getMappings(): array
    {
        return self::$mappings;
    }

    /**
     * The discovered {@see HealthContributor} classes, merged into `/actuator/health`
     * by the aggregator. Resolved from the container per request.
     *
     * @param list<class-string<HealthContributor>> $contributors
     */
    public static function setContributors(array $contributors): void
    {
        self::$contributors = $contributors;
    }

    /**
     * @return list<class-string<HealthContributor>>
     */
    public static function getContributors(): array
    {
        return self::$contributors;
    }

    // ── System info helpers (used by HealthIndicator) ─────────────────────────

    public static function cpu(): array
    {
        $cores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $count = substr_count((string) file_get_contents('/proc/cpuinfo'), 'processor');
            if ($count > 0) {
                $cores = $count;
            }
        }
        return [
            'load_average' => sys_getloadavg(),
            'core_count'   => $cores,
        ];
    }

    public static function memory(): array
    {
        $limit = ini_get('memory_limit');
        return [
            'usage' => memory_get_usage(true),
            'peak'  => memory_get_peak_usage(true),
            'limit' => $limit === '-1' ? -1 : self::toBytes((string) $limit),
        ];
    }

    public static function disk(): array
    {
        $free  = (float) disk_free_space('/');
        $total = (float) disk_total_space('/');
        return [
            'free'          => $free,
            'total'         => $total,
            'usage_percent' => $total > 0 ? round((1 - $free / $total) * 100, 2) : 0,
        ];
    }

    public static function system(): array
    {
        return [
            'os'       => php_uname('s'),
            'release'  => php_uname('r'),
            'hostname' => gethostname() ?: 'unknown',
        ];
    }

    public static function uptimeSeconds(): ?int
    {
        $content = @file_get_contents('/proc/uptime');
        if ($content === false) {
            return null;
        }
        return (int) explode(' ', $content)[0];
    }

    private static function toBytes(string $val): int
    {
        $val  = trim($val);
        $unit = strtolower($val[-1]);
        $num  = (int) $val;
        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }
}
