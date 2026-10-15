<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Cache;

use Flytachi\Winter\Redis\Config\RedisConfig;

/**
 * The throw-away Redis the integration tests talk to: `127.0.0.1:56379` unless
 * `WINTER_TEST_REDIS_HOST` / `WINTER_TEST_REDIS_PORT` say otherwise.
 *
 * ```
 * docker run -d --rm --name wv-redis -p 56379:6379 redis:7-alpine
 * ```
 */
final class TestRedisEndpoint extends RedisConfig
{
    public function setUp(): void
    {
        $this->host = (string) (getenv('WINTER_TEST_REDIS_HOST') ?: '127.0.0.1');
        $this->port = (int) (getenv('WINTER_TEST_REDIS_PORT') ?: 56379);
    }
}
