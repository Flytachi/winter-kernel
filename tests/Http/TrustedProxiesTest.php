<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http;

use Flytachi\Winter\Kernel\Http\TrustedProxies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrustedProxiesTest extends TestCase
{
    public function test_nobody_is_trusted_by_default(): void
    {
        $proxies = TrustedProxies::none();

        self::assertFalse($proxies->trusts('10.0.0.5'));
        self::assertFalse($proxies->trusts('127.0.0.1'));
        self::assertSame([], $proxies->list());
    }

    public function test_an_address_and_a_network_are_trusted(): void
    {
        $proxies = TrustedProxies::of('192.168.1.5', '10.0.0.0/8');

        self::assertTrue($proxies->trusts('192.168.1.5'));
        self::assertTrue($proxies->trusts('10.244.3.17'));
        self::assertFalse($proxies->trusts('192.168.1.6'));
        self::assertFalse($proxies->trusts('11.0.0.1'));
        self::assertSame(['192.168.1.5', '10.0.0.0/8'], $proxies->list());
    }

    public function test_ipv6_networks_are_trusted(): void
    {
        $proxies = TrustedProxies::of('fd00::/8', '::1');

        self::assertTrue($proxies->trusts('fd12:3456::1'));
        self::assertTrue($proxies->trusts('::1'));
        self::assertFalse($proxies->trusts('2001:db8::1'));
        self::assertFalse($proxies->trusts('10.0.0.1'), 'an IPv4 peer is never in an IPv6 network');
    }

    public function test_a_mapped_ipv4_peer_matches_its_ipv4_network(): void
    {
        // A server on `::` sees IPv4 clients as ::ffff:a.b.c.d.
        self::assertTrue(TrustedProxies::of('172.16.0.0/12')->trusts('::ffff:172.18.0.3'));
    }

    public function test_a_star_trusts_everyone(): void
    {
        $proxies = TrustedProxies::of('*');

        self::assertTrue($proxies->trusts('203.0.113.7'));
        self::assertTrue($proxies->trustsAll());
    }

    public function test_no_argument_trusts_nobody(): void
    {
        self::assertFalse(TrustedProxies::of()->trusts('10.0.0.1'));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalid(): iterable
    {
        yield 'a name' => [['nginx']];
        yield 'a prefix too long for IPv4' => [['10.0.0.0/33']];
        yield 'a prefix too long for IPv6' => [['fd00::/129']];
        yield 'a negative prefix' => [['10.0.0.0/-1']];
        yield 'an empty value' => [['']];
        yield 'a star among addresses' => [['*', '10.0.0.0/8']];
    }

    /** @param list<string> $values */
    #[DataProvider('invalid')]
    public function test_a_wrong_value_is_refused_by_name(array $values): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrustedProxies::of(...$values);
    }

    public function test_the_refusal_names_the_value(): void
    {
        try {
            TrustedProxies::of('10.0.0.0/8', 'proxy.local');
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("'proxy.local'", $e->getMessage());
        }
    }

    public function test_the_client_is_the_first_untrusted_hop_from_the_right(): void
    {
        // The client forged the left part; nginx 10.0.0.5 appended the real address.
        $proxies = TrustedProxies::of('10.0.0.0/8');

        self::assertSame('203.0.113.7', $proxies->client(['1.2.3.4', '203.0.113.7']));
    }

    public function test_trusted_hops_on_the_right_are_skipped(): void
    {
        // CDN edge 10.1.0.9 → ingress 10.0.0.5 → app: both proxies are ours.
        $proxies = TrustedProxies::of('10.0.0.0/8');

        self::assertSame('198.51.100.2', $proxies->client(['1.2.3.4', '198.51.100.2', '10.1.0.9']));
    }

    public function test_a_private_client_stays_itself(): void
    {
        $proxies = TrustedProxies::of('10.0.0.5');

        self::assertSame('192.168.7.20', $proxies->client(['192.168.7.20']));
    }

    public function test_every_hop_trusted_gives_the_leftmost(): void
    {
        self::assertSame('1.2.3.4', TrustedProxies::of('*')->client(['1.2.3.4', '203.0.113.7']));
    }

    public function test_garbage_in_the_chain_is_passed_over(): void
    {
        $proxies = TrustedProxies::of('10.0.0.0/8');

        self::assertSame('203.0.113.7', $proxies->client(['203.0.113.7', 'unknown', '10.0.0.9']));
        self::assertNull($proxies->client(['unknown', '']));
    }
}
