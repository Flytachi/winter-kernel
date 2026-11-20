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
        self::assertSame('1.2.3.4', TrustedProxies::of('10.0.0.0/8')->client(['1.2.3.4', '10.0.0.9']));
    }

    public function test_a_star_trusts_only_the_peer_not_the_chain(): void
    {
        // The client sent 1.1.1.1; the one proxy appended 2.2.2.2 — the client it saw.
        self::assertSame('2.2.2.2', TrustedProxies::of('*')->client(['1.1.1.1', '2.2.2.2']));
    }

    public function test_a_hop_that_is_no_address_stops_the_walk(): void
    {
        // Past a hop nobody can read, the rest was written by whoever: the client is unknown.
        $proxies = TrustedProxies::of('10.0.0.0/8');

        self::assertNull($proxies->client(['6.6.6.6', 'unknown']));
        self::assertNull($proxies->client(['6.6.6.6', '_hidden', '10.0.0.9']));
        self::assertNull($proxies->client(['unknown', '']));
    }

    public function test_a_port_or_a_zone_is_cut_off_a_hop(): void
    {
        $proxies = TrustedProxies::of('10.0.0.0/8');

        self::assertSame('9.9.9.9', $proxies->client(['6.6.6.6', '9.9.9.9:1234']));
        self::assertSame('2001:db8::9', $proxies->client(['6.6.6.6', '[2001:db8::9]:443']));
        self::assertSame('fe80::9', $proxies->client(['6.6.6.6', 'fe80::9%eth0']));
    }

    public function test_every_spelling_of_a_mapped_address_is_one_address(): void
    {
        $proxies = TrustedProxies::of('10.0.0.0/8');

        self::assertTrue($proxies->trusts('0:0:0:0:0:ffff:10.0.0.2'));
        self::assertTrue($proxies->trusts('::ffff:a00:2'));
        self::assertSame('9.9.9.9', $proxies->client(['::ffff:909:909']));
    }
}
