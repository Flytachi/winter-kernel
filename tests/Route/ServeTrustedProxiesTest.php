<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Tests\Route\Fixtures\ServerProcess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A real server, a forged header: the issue's `curl -H "X-Real-IP: 1.2.3.4"` straight to
 * the service, past its proxy.
 */
#[Group('integration')]
final class ServeTrustedProxiesTest extends TestCase
{
    private ?ServerProcess $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    /** @param array<string, string> $env */
    private function start(array $env = []): void
    {
        $this->server = new ServerProcess(env: ['LOG_LEVEL' => 'info', ...$env]);
        self::assertTrue($this->server->start(), (string) $this->server->log());
    }

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers): array
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= "{$name}: {$value}\r\n";
        }
        $context = stream_context_create(['http' => ['header' => $lines, 'timeout' => 5, 'ignore_errors' => true]]);
        return json_decode((string) file_get_contents($this->server?->url($path), false, $context), true);
    }

    public function test_a_forged_address_is_ignored_and_logged_as_a_warning(): void
    {
        $this->start();

        self::assertSame(['ip' => '127.0.0.1'], $this->get('/demo/ip', ['X-Real-IP' => '1.2.3.4']));
        self::assertSame('http', $this->get('/demo/scheme', ['X-Forwarded-Proto' => 'https'])['scheme']);
        usleep(300_000);

        $log = (string) $this->server?->log();
        self::assertStringContainsString('Trusted proxies: none — forwarded headers ignored', $log);
        self::assertMatchesRegularExpression('/\[WARN \].*Forwarded headers from 127\.0\.0\.1 ignored \(X-Real-IP\): no proxy is trusted/', $log);
        self::assertSame(1, substr_count($log, 'Forwarded headers from 127.0.0.1'), 'once per sender');
    }

    public function test_a_trusted_proxy_is_believed(): void
    {
        $this->start(['TEST_TRUSTED_PROXIES' => '127.0.0.1']);

        self::assertSame(['ip' => '203.0.113.7'], $this->get('/demo/ip', ['X-Forwarded-For' => '1.2.3.4, 203.0.113.7']));
        self::assertSame('https', $this->get('/demo/scheme', ['X-Forwarded-Proto' => 'https'])['scheme']);

        $log = (string) $this->server?->log();
        self::assertStringContainsString('Trusted proxies: 127.0.0.1', $log);
        self::assertStringNotContainsString('Forwarded headers from', $log);
    }
}
