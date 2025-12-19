<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Health;

use Flytachi\Winter\Cache\Config\Common\RedisConfigInterface;
use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Kernel\Factory\Mapping;
use Flytachi\Winter\Kernel\Kernel;

class HealthIndicator implements HealthIndicatorInterface
{
    private const int DEGRADED_THRESHOLD_MS = 500;

    final public function health(array $args = []): array
    {
        $projectFiles = Mapping::scanProjectFiles();
        $components = [
            'db' => $this->dbHealth($projectFiles),
            'cache' => $this->cacheHealth($projectFiles),
            'disk' => $this->diskHealth(),
            'memory' => $this->memoryHealth(),
            'custom' => $this->customHealth()
        ];

        $statuses = array_column($components, 'status');
        $overallStatus = 'up';

        if (in_array('down', $statuses, true)) {
            $overallStatus = 'down';
        } elseif (in_array('degraded', $statuses, true)) {
            $overallStatus = 'degraded';
        }

        return [
            'status' => $overallStatus,
            'components' => $components
        ];
    }

    public function info(array $args = []): array
    {
        $framework = Kernel::info();
        $project = Kernel::projectInfo();
        return [
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => php_sapi_name(),
                'zend_version' => zend_version(),
            ],
            'framework' => [
                'name' => $framework['name'] ?? null,
                'version' => $framework['version'] ?? null,
            ],
            'project' => isset($project['extra']['project'])
                ? [
                    'name' => $project['extra']['project']['name'] ?? '',
                    'version' => $project['extra']['project']['version'] ?? '',
                    'description' => $project['extra']['project']['description'] ?? '',
                ]
                : null
        ];
    }

    final public function metrics(array $args = []): array
    {
        return [
            'cpu' => Health::cpu(),
            'memory' => Health::memory(),
            'disk' => Health::disk(),
            'system' => Health::system(),
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'zend_version' => zend_version(),
                'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
            ],
            'opcache' => function_exists('opcache_get_status')
                ? opcache_get_status(false)
                : null,
            'requests' => [
                'method' => $_SERVER['REQUEST_METHOD'],
                'uri' => $_SERVER['REQUEST_URI'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ],
            'uptime_seconds' => Health::uptimeSeconds(),
        ];
    }

    public function env(array $args = []): array
    {
        return [];
    }

    public function loggers(array $args = []): array
    {
        $allowedLevels = env('LOGGER_LEVEL_ALLOW');
        $allowedLevels = array_map('trim', explode(',', $allowedLevels));
        return [
            'levels' => $allowedLevels,
        ];
    }

    public function mappings(array $args = []): array
    {
        $list = [];
        $declaration = Mapping::scanningDeclaration();
        foreach ($declaration->getChildren() as $item) {
            $list[] = [
                'method' => $item->getMethod() ?: '?',
                'path' => $item->getUrl(),
                'handler' => $item->getClassName() . '->' . $item->getClassMethod(),
                'handlerClass' => $item->getClassName(),
                'handlerMethod' => $item->getClassMethod(),
                'middlewares' => $item->getMiddlewareClassNames(),
                'arguments' => $item->getMethodArgs()
            ];
        }
        return $list;
    }

    final protected function dbHealth(array $projectFiles): array
    {
        $refClasses = Mapping::scanRefClasses($projectFiles, DbConfigInterface::class);
        $details = [];
        $worstStatus = 'up';

        foreach ($refClasses as $refClass) {
            /** @var DbConfigInterface $config */
            $config = $refClass->newInstance();
            $config->sepUp();

            try {
                $result = $config->pingDetail();

                // item status
                if (!$result['status']) {
                    $status = 'down';
                } elseif ($result['latency'] !== null && $result['latency'] >= self::DEGRADED_THRESHOLD_MS) {
                    $status = 'degraded';
                } else {
                    $status = 'up';
                }

                // status
                if ($status === 'down') {
                    $worstStatus = 'down';
                } elseif ($status === 'degraded' && $worstStatus !== 'down') {
                    $worstStatus = 'degraded';
                }

                $details[$refClass->getName()] = [
                    'status' => $status,
                    'driver' => $config->getDriver(),
                    'latency' => $result['latency'],
                    'error' => $result['error']
                ];
            } catch (\Throwable $e) {
                $details[$refClass->getName()] = [
                    'status' => 'down',
                    'driver' => $config->getDriver(),
                    'latency' => null,
                    'error' => $e->getMessage()
                ];
                $worstStatus = 'down';
            }
        }

        return [
            'status' => $worstStatus,
            'details' => $details
        ];
    }

    final protected function cacheHealth(array $projectFiles): array
    {
        $refClasses = Mapping::scanRefClasses($projectFiles, RedisConfigInterface::class);
        $details = [];
        $worstStatus = 'up';

        foreach ($refClasses as $refClass) {
            /** @var RedisConfigInterface $config */
            $config = $refClass->newInstance();
            $config->sepUp();

            try {
                $result = $config->pingDetail();

                $status = match (true) {
                    !$result['status'] => 'down',
                    $result['latency'] > self::DEGRADED_THRESHOLD_MS => 'degraded',
                    default => 'up'
                };

                if ($status === 'down' || ($status === 'degraded' && $worstStatus === 'up')) {
                    $worstStatus = $status;
                }

                $details[$refClass->getName()] = [
                    'status' => $status,
                    'latency' => $result['latency'],
                    'error' => $result['error']
                ];
            } catch (\Throwable $e) {
                $details[$refClass->getName()] = [
                    'status' => 'down',
                    'error' => $e->getMessage()
                ];
                $worstStatus = 'down';
            }
        }

        return [
            'status' => $worstStatus,
            'details' => $details
        ];
    }

    final protected function diskHealth(): array
    {
        $diskInfo = Health::disk();
        $usagePercent = $diskInfo['usage_percent'];

        $status = 'up';
        $warning = null;

        if ($usagePercent >= 90) {
            $status = 'down';
            $warning = 'Disk usage above 90% of total capacity';
        } elseif ($usagePercent >= 80) {
            $status = 'degraded';
            $warning = 'Disk usage above 80% of total capacity';
        }

        return [
            'status' => $status,
            'details' => array_filter([
                'free' => $diskInfo['free'],
                'total' => $diskInfo['total'],
                'usage_percent' => round($usagePercent, 2),
                'warning' => $warning,
            ]),
        ];
    }

    final protected function memoryHealth(): array
    {
        $memoryInfo = Health::memory();
        $limit = $memoryInfo['limit'];
        $usage = $memoryInfo['usage'];
        $usagePercent = $limit > 0 ? ($usage / $limit) * 100 : 0;

        $status = 'up';
        $warning = null;

        if ($limit > 0) {
            if ($usagePercent >= 90) {
                $status = 'down';
                $warning = 'Memory usage above 90% of the limit';
            } elseif ($usagePercent >= 80) {
                $status = 'degraded';
                $warning = 'Memory usage above 80% of the limit';
            }
        }

        return [
            'status' => $status,
            'details' => array_filter([
                'usage' => $usage,
                'peak' => $memoryInfo['peak'],
                'limit' => $limit,
                'usage_percent' => round($usagePercent, 2),
                'warning' => $warning,
            ]),
        ];
    }

    protected function customHealth(): array
    {
        return [
            'status' => 'up',
            'details' => []
        ];
    }
}
