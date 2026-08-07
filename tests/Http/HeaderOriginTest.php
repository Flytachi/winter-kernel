<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http;

use Flytachi\Winter\Kernel\Http\Header;
use Flytachi\Winter\Kernel\Tests\Http\Fixtures\OriginProbeRequest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The request origin is surfaced correctly and computed only when someone asks.
 *
 * Deriving scheme/host/port from proxy headers belongs to the request adapter and is
 * covered by {@see SwooleRequestBaseUrlTest}. What `Header` owns is narrower: expose
 * whatever the request reports, and do not pay for it on requests that never ask.
 * Nothing in the kernel reads these four getters — only applications do — so the work
 * was speculative on every single request.
 */
final class HeaderOriginTest extends TestCase
{
    protected function setUp(): void
    {
        self::reset();
    }

    protected function tearDown(): void
    {
        self::reset();
    }

    private static function reset(): void
    {
        new ReflectionProperty(Header::class, 'bag')->setValue(null, []);
        new ReflectionProperty(Header::class, 'origin')->setValue(null, []);
        new ReflectionProperty(Header::class, 'request')->setValue(null, null);
    }

    public function test_the_origin_is_reported_as_the_request_states_it(): void
    {
        Header::init(new OriginProbeRequest('https', 'example.test', 8443, 'https://example.test:8443'));

        self::assertSame('https', Header::getScheme());
        self::assertSame('example.test', Header::getHost());
        self::assertSame(8443, Header::getPort());
        self::assertSame('https://example.test:8443', Header::getBaseUrl());
    }

    /**
     * The point of the change: a request that never reads the origin never computes it.
     */
    public function test_a_request_that_never_asks_pays_nothing(): void
    {
        $request = new OriginProbeRequest();

        Header::init($request);
        Header::get('Content-Type');
        Header::getIpAddress();

        self::assertSame(
            0,
            $request->originCalls,
            'Nothing read the origin, so the request should not have been asked for it.',
        );
    }

    /**
     * Reading repeatedly must not recompute: the four getters together used to cost
     * six calls into the request, because getBaseUrl() re-derives scheme, host and port.
     */
    public function test_the_origin_is_computed_once_however_often_it_is_read(): void
    {
        $request = new OriginProbeRequest();
        Header::init($request);

        for ($i = 0; $i < 5; $i++) {
            Header::getScheme();
            Header::getHost();
            Header::getPort();
            Header::getBaseUrl();
        }

        self::assertSame(1, $request->originCalls, 'The origin should be derived exactly once.');
    }

    /**
     * A later request must not inherit the previous one's origin — the failure a
     * per-request cache introduces when it is never invalidated.
     */
    public function test_a_later_request_does_not_inherit_the_previous_origin(): void
    {
        Header::init(new OriginProbeRequest('http', 'first.test', 80, 'http://first.test'));
        self::assertSame('http://first.test', Header::getBaseUrl());

        Header::init(new OriginProbeRequest('https', 'second.test', 8443, 'https://second.test:8443'));
        self::assertSame('http://second.test:8443', str_replace('https', 'http', Header::getBaseUrl() ?? ''));
        self::assertSame(8443, Header::getPort());
        self::assertSame('second.test', Header::getHost());
    }

    /**
     * The raw `Host` header keeps its port; the origin's host is stripped. The two
     * live side by side and must not be confused for one another.
     */
    public function test_the_raw_host_header_survives_beside_the_stripped_origin(): void
    {
        Header::init(new OriginProbeRequest('http', 'example.test', 8080, 'http://example.test:8080', [
            'Host' => 'example.test:8080',
        ]));

        self::assertSame('example.test:8080', Header::get('Host'));
        self::assertSame('example.test', Header::getHost());
    }
}
