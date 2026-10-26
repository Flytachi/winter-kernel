<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Runtime;

/**
 * The running application a component belongs to — carried in the component's status
 * record, so whoever reads it knows the component is the application's to run, and
 * which run of the application it was.
 *
 * A PID alone would not do: after a restart it may belong to another run, or to a
 * process that is not an application at all. The start time and the host pin it.
 */
final readonly class ManagedBy implements \JsonSerializable
{
    /**
     * @param class-string<\Flytachi\Winter\Kernel\WinterApplication> $appClass The application.
     * @param int $pid The application's master process.
     * @param int $startedAt When that master started (Unix time).
     * @param string $host Where it runs — {@see ApplicationRuntime::host()}.
     */
    public function __construct(
        public string $appClass,
        public int $pid,
        public int $startedAt,
        public string $host,
    ) {
    }

    /** Whether this is the given run of the application. */
    public function isRun(ApplicationRecord $app): bool
    {
        return $this->appClass === $app->appClass
            && $this->pid === $app->pid
            && $this->startedAt === $app->startedAt
            && $this->host === $app->host;
    }

    /** Whether this run of the application is still going. */
    public function isAlive(): bool
    {
        $app = ApplicationRuntime::live($this->appClass);
        return $app !== null && $this->isRun($app);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['app' => $this->appClass, 'pid' => $this->pid, 'started_at' => $this->startedAt, 'host' => $this->host];
    }
}
