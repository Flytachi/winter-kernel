<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Health;

use Flytachi\Winter\Base\Header;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Base\Interface\ActuatorItemInterface;
use Flytachi\Winter\Kernel\Exception\ClientError;
use Flytachi\Winter\Kernel\Factory\Middleware\MiddlewareInterface;
use Flytachi\Winter\Kernel\Http\Rendering;
use Flytachi\Winter\Kernel\Stereotype\Output\ResponseJson;

final readonly class Health implements ActuatorItemInterface
{
    /**
     * @param class-string<HealthIndicatorInterface> $indicatorClass
     * @param class-string<MiddlewareInterface>|null $middlewareClass
     */
    public function __construct(
        private string $indicatorClass = HealthIndicator::class,
        private ?string $middlewareClass = null
    ) {
    }

    public function run(): void
    {
        Header::setHeaders();
        $data = parseUrlDetail($_SERVER['REQUEST_URI']);

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_starts_with($data['path'], '/actuator')) {
            /** @var HealthIndicatorInterface $indicator */
            $indicator = new $this->indicatorClass();

            $metta = trim(str_replace('/actuator', '', $data['path']), '/');
            $method = trim($metta, '/');

            $render = new Rendering();

            try {
                if ($this->middlewareClass !== null) {
                    /** @var MiddlewareInterface $middleware */
                    $middleware = new $this->middlewareClass();
                    $middleware->optionBefore();
                }
                if (!method_exists($indicator, $method)) {
                    throw new ClientError(
                        "{$_SERVER['REQUEST_METHOD']} '{$data['path']}' url not found",
                        HttpCode::NOT_FOUND->value
                    );
                }
                $result = $indicator->{$method}();
                $render->setResource(new ResponseJson($result));
            } catch (\Throwable $e) {
                $httpCode = HttpCode::tryFrom((int) $e->getCode()) ?: HttpCode::UNKNOWN_ERROR;
                $render->setResource(new ResponseJson(
                    [
                        'code' => $e->getCode(),
                        'message' => $e->getMessage()
                    ],
                    $httpCode
                ));
            }

            $render->render();
        }
    }

    public static function cpu(): array
    {
        exec('nproc', $output, $result_code);
        return [
            'load_average' => sys_getloadavg(),
            'core_count' => ($result_code === 0 && isset($output[0]) && is_numeric($output[0]))
                ? (int) $output[0] : 1
        ];
//        return [
//            'load_average' => sys_getloadavg(),
//            'core_count' => (int) shell_exec('nproc') ?: 1,
//        ];
    }

    public static function memory(): array
    {
        $limit = ini_get('memory_limit');
        return [
            'usage' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => $limit == '-1' ? -1 : self::convertToBytes($limit),
        ];
    }

    private static function convertToBytes(string $val): int
    {
        $val = trim($val);
        $unit = strtolower(substr($val, -1));
        $num = (int) $val;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $val,
        };
    }

    public static function system(): array
    {
        return [
            'os' => php_uname('s'),
            'release' => php_uname('r'),
            'hostname' => gethostname(),
        ];
    }

    public static function disk(): array
    {
        return [
            'free' => disk_free_space("/"),
            'total' => disk_total_space("/"),
            'usage_percent' => round(
                (1 - disk_free_space("/") /
                    disk_total_space("/")
                ) * 100,
                2
            )
        ];
    }

    public static function uptimeSeconds(): ?int
    {
        $uptime_content = @file_get_contents('/proc/uptime');
        if ($uptime_content === false) {
            return null;
        }
        $parts = explode(' ', $uptime_content);
        return (int) $parts[0];
//        return (int) shell_exec('awk \'{print int($1)}\' /proc/uptime') ?: null;
    }
}
