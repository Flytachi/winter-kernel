<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Cache;

use Psr\Log\AbstractLogger;

/**
 * Keeps what the cache logged, so a test can assert on the level and the wording.
 */
final class CacheWarningRecorder extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }
}
