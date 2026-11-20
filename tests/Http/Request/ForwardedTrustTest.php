<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request;

use Flytachi\Winter\Kernel\Http\Adapter\ForwardedHeaders;
use Flytachi\Winter\Kernel\Http\Adapter\FpmRequest;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest;
use Flytachi\Winter\Kernel\Http\ProxyHeader;
use Flytachi\Winter\Kernel\Http\TrustedProxies;
use Flytachi\Winter\Logger\LoggerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use Swoole\Http\Request;

/**
 * A client's word about itself is not believed: the forwarded headers count only on a
 * request from a trusted proxy — the issue was `curl -H "X-Real-IP: 1.2.3.4"` straight to
 * a NodePort, and the audit log took 1.2.3.4 for the admin's address.
 */
final class ForwardedTrustTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $records = [];
    private array $originalLoggers = [];

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(Request::class, false)) {
            require_once dirname(__DIR__, 3) . '/vendor/swoole/ide-helper/src/swoole/Swoole/Http/Request.php';
        }
    }

    protected function setUp(): void
    {
        ForwardedHeaders::resetWarnings();
        $records = &$this->records;
        $recorder = new class ($records) extends AbstractLogger {
            public function __construct(private array &$records)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $cache = new ReflectionProperty(LoggerFactory::class, 'cache');
        $channel = new ReflectionProperty(LoggerFactory::class, 'defaultChannel')->getValue();
        $this->originalLoggers = $cache->getValue();
        $cache->setValue(null, [$channel . ':' . TrustedProxies::class => $recorder] + $this->originalLoggers);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(LoggerFactory::class, 'cache')->setValue(null, $this->originalLoggers);
        ForwardedHeaders::resetWarnings();
    }

    /** @param array<string, string> $headers */
    private static function request(array $headers, string $peer, ?TrustedProxies $proxies = null, int $port = 8000): SwooleRequest
    {
        $raw = new Request();
        $raw->header = $headers;
        $raw->server = ['remote_addr' => $peer, 'server_port' => $port];
        return new SwooleRequest($raw, false, $proxies);
    }

    // ── Nobody trusted: the connection speaks ──────────────────────────────────

    public function test_a_forged_real_ip_is_ignored_by_default(): void
    {
        self::assertSame('198.51.100.20', self::request(['x-real-ip' => '1.2.3.4'], '198.51.100.20')->getClientIp());
    }

    public function test_every_forwarded_header_is_ignored_by_default(): void
    {
        $request = self::request([
            'host' => 'app.example.com:8000',
            'forwarded' => 'for=1.2.3.4;proto=https;host=evil.example',
            'x-forwarded-for' => '1.2.3.4',
            'x-forwarded-proto' => 'https',
            'x-forwarded-host' => 'evil.example',
            'x-forwarded-port' => '443',
        ], '198.51.100.20');

        self::assertSame('198.51.100.20', $request->getClientIp());
        self::assertSame('http', $request->getScheme());
        self::assertSame('app.example.com', $request->getHost());
        self::assertSame(8000, $request->getPort());
        self::assertSame('http://app.example.com:8000', $request->getBaseUrl());
    }

    public function test_a_request_past_the_proxy_is_not_believed(): void
    {
        // The ingress is trusted; a client at the NodePort is not.
        $proxies = TrustedProxies::of('10.244.0.0/16');

        $request = self::request(['x-real-ip' => '1.2.3.4', 'x-forwarded-host' => 'evil.example', 'host' => 'app'], '192.168.0.40', $proxies);

        self::assertSame('192.168.0.40', $request->getClientIp());
        self::assertSame('app', $request->getHost());
    }

    // ── A trusted proxy is believed ────────────────────────────────────────────

    public function test_a_trusted_proxy_tells_the_client_scheme_and_host(): void
    {
        $request = self::request([
            'host' => 'backend:8000',
            'x-real-ip' => '203.0.113.7',
            'x-forwarded-proto' => 'https',
            'x-forwarded-host' => 'app.example.com',
        ], '10.244.1.3', TrustedProxies::of('10.244.0.0/16')->withHeaders(ProxyHeader::XForwarded, ProxyHeader::XRealIp));

        self::assertSame('203.0.113.7', $request->getClientIp());
        self::assertSame('https', $request->getScheme());
        self::assertSame('app.example.com', $request->getHost());
        self::assertSame('https://app.example.com', $request->getBaseUrl());
    }

    public function test_the_forwarded_for_chain_is_read_from_the_right(): void
    {
        // nginx appended the real address to what the client forged.
        $request = self::request(['x-forwarded-for' => '1.2.3.4, 203.0.113.7'], '10.0.0.5', TrustedProxies::of('10.0.0.0/8'));

        self::assertSame('203.0.113.7', $request->getClientIp());
    }

    public function test_the_forwarded_header_chain_is_read_from_the_right(): void
    {
        $request = self::request(
            ['forwarded' => 'for=1.2.3.4, for="[2001:db8::7]:4711";proto=https'],
            '10.0.0.5',
            TrustedProxies::of('10.0.0.0/8')->withHeaders(ProxyHeader::Forwarded),
        );

        self::assertSame('2001:db8::7', $request->getClientIp());
    }

    public function test_a_client_in_a_private_network_stays_itself(): void
    {
        $request = self::request(['x-forwarded-for' => '192.168.7.20'], '10.0.0.5', TrustedProxies::of('10.0.0.5'));

        self::assertSame('192.168.7.20', $request->getClientIp());
    }

    public function test_a_star_believes_every_peer(): void
    {
        $request = self::request(
            ['x-real-ip' => '203.0.113.7', 'x-forwarded-proto' => 'https'],
            '172.18.0.3',
            TrustedProxies::of('*')->withHeaders(ProxyHeader::XForwarded, ProxyHeader::XRealIp),
        );

        self::assertSame('203.0.113.7', $request->getClientIp());
        self::assertSame('https', $request->getScheme());
    }

    public function test_the_fpm_adapter_trusts_the_same_way(): void
    {
        $saved = $_SERVER;
        try {
            $_SERVER = ['REMOTE_ADDR' => '198.51.100.20', 'HTTP_X_REAL_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_PROTO' => 'https'];
            self::assertSame('198.51.100.20', new FpmRequest()->getClientIp());
            self::assertSame('http', new FpmRequest()->getScheme());

            $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
            $trusted = new FpmRequest(TrustedProxies::of('10.0.0.0/8')->withHeaders(ProxyHeader::XForwarded, ProxyHeader::XRealIp));
            self::assertSame('1.2.3.4', $trusted->getClientIp());
            self::assertSame('https', $trusted->getScheme());
        } finally {
            $_SERVER = $saved;
        }
    }

    // ── Only the headers the proxy sets ────────────────────────────────────────

    public function test_by_default_only_x_forwarded_is_believed(): void
    {
        // nginx appends X-Forwarded-For and passes a client's Forwarded and X-Real-IP on.
        $request = self::request([
            'host' => 'good.example',
            'forwarded' => 'for=6.6.6.6;host=evil.example;proto=https',
            'x-real-ip' => '6.6.6.6',
            'x-forwarded-for' => '9.9.9.9',
            'x-forwarded-host' => 'good.example',
        ], '10.0.0.2', TrustedProxies::of('10.0.0.0/8'));

        self::assertSame('9.9.9.9', $request->getClientIp());
        self::assertSame('good.example', $request->getHost());
        self::assertSame('http', $request->getScheme());
    }

    public function test_x_forwarded_for_wins_over_x_real_ip_when_both_are_believed(): void
    {
        $proxies = TrustedProxies::of('10.0.0.0/8')->withHeaders(ProxyHeader::XForwarded, ProxyHeader::XRealIp);

        self::assertSame('9.9.9.9', self::request(['x-real-ip' => '6.6.6.6', 'x-forwarded-for' => '9.9.9.9'], '10.0.0.2', $proxies)->getClientIp());
        self::assertSame('9.9.9.9', self::request(['x-real-ip' => '9.9.9.9'], '10.0.0.2', $proxies)->getClientIp());
    }

    public function test_an_x_real_ip_naming_a_proxy_is_not_the_client(): void
    {
        $proxies = TrustedProxies::of('10.0.0.0/8')->withHeaders(ProxyHeader::XRealIp);

        self::assertSame('10.0.0.2', self::request(['x-real-ip' => '10.0.0.7'], '10.0.0.2', $proxies)->getClientIp());
    }

    public function test_forwarded_proto_and_host_come_from_the_client_element(): void
    {
        // The client wrote the first element; the proxy appended the second.
        $request = self::request(
            ['host' => 'backend', 'forwarded' => 'for=6.6.6.6;host=evil.example;proto=https, for=9.9.9.9;host=good.example;proto=http'],
            '10.0.0.2',
            TrustedProxies::of('10.0.0.0/8')->withHeaders(ProxyHeader::Forwarded),
        );

        self::assertSame('9.9.9.9', $request->getClientIp());
        self::assertSame('good.example', $request->getHost());
        self::assertSame('http', $request->getScheme());
    }

    public function test_a_quoted_forwarded_value_is_not_split(): void
    {
        $request = self::request(
            ['forwarded' => 'for="9.9.9.9";xhost=evil.example;host="good.example"'],
            '10.0.0.2',
            TrustedProxies::of('10.0.0.0/8')->withHeaders(ProxyHeader::Forwarded),
        );

        self::assertSame('9.9.9.9', $request->getClientIp());
        self::assertSame('good.example', $request->getHost());
    }

    public function test_a_port_out_of_range_is_ignored(): void
    {
        $request = self::request(['host' => 'app.example', 'x-forwarded-proto' => 'https', 'x-forwarded-port' => '99999'], '10.0.0.2', TrustedProxies::of('10.0.0.0/8'));

        self::assertSame('https://app.example', $request->getBaseUrl());
    }

    // ── The warning ────────────────────────────────────────────────────────────

    public function test_forwarded_headers_from_an_untrusted_peer_are_a_warning(): void
    {
        self::request(['x-real-ip' => '1.2.3.4', 'x-forwarded-proto' => 'https'], '172.18.0.3');

        self::assertCount(1, $this->records);
        [$level, $message, $context] = $this->records[0];
        self::assertSame('warning', $level);
        self::assertStringContainsString('172.18.0.3', $message);
        self::assertStringContainsString('X-Real-IP, X-Forwarded-Proto', $message);
        self::assertStringContainsString('no proxy is trusted', $message);
        self::assertStringContainsString("trustedProxies('172.18.0.3')", $message);
        self::assertSame('1.2.3.4', $context['x-real-ip']);
    }

    public function test_with_proxies_set_the_warning_says_it_came_past_them(): void
    {
        self::request(['x-real-ip' => '1.2.3.4'], '192.168.0.40', TrustedProxies::of('10.244.0.0/16'));

        self::assertStringContainsString('not a trusted proxy (10.244.0.0/16)', $this->records[0][1]);
    }

    public function test_a_peer_is_warned_about_once(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::request(['x-real-ip' => '1.2.3.4'], '172.18.0.3');
        }
        self::request(['x-real-ip' => '1.2.3.4'], '172.18.0.4');

        self::assertCount(2, $this->records);
    }

    public function test_many_peers_stop_at_a_limit(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            self::request(['x-real-ip' => '1.2.3.4'], "198.51.100.{$i}");
        }

        self::assertCount(ForwardedHeaders::WARN_PEERS + 1, $this->records);
        self::assertStringContainsString('no longer logged', end($this->records)[1]);
    }

    public function test_a_trusted_proxy_or_a_plain_request_is_not_a_warning(): void
    {
        self::request(['x-real-ip' => '203.0.113.7'], '10.0.0.5', TrustedProxies::of('10.0.0.0/8'));
        self::request(['host' => 'app'], '198.51.100.20');

        self::assertSame([], $this->records);
    }

    public function test_a_forged_value_cannot_break_the_log_line(): void
    {
        self::request(['x-real-ip' => "1.2.3.4\nERROR fake line"], '172.18.0.3');

        self::assertStringNotContainsString("\n", $this->records[0][1]);
        self::assertStringNotContainsString("\n", $this->records[0][2]['x-real-ip']);
    }
}
