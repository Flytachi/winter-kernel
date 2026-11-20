<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

/**
 * The proxies whose word about the client is believed, and which of their headers.
 * A request from anyone else is the client itself: its forwarded headers are what it
 * typed, and the connection's own address, scheme and host are used instead.
 *
 * Nobody is trusted until the application says so, in
 * {@see \Flytachi\Winter\Kernel\App\Config\ServerSettings::trustedProxies()}: a service
 * reachable past its proxy — a NodePort, a published container port — would otherwise
 * take any `X-Real-IP` a client sends for the client's address. Of a trusted proxy only
 * {@see ProxyHeader::XForwarded} is read unless more are named: a proxy forwards the
 * headers it does not set as the client sent them.
 *
 * @link https://winterframe.net/docs/web-configuration Trusted proxies
 */
final class TrustedProxies
{
    /** The proxies of the running server, for what is not built with its settings (a WebSocket). */
    private static ?self $installed = null;

    /**
     * @param list<string> $list As given, for the boot log.
     * @param list<array{string, int}> $networks Packed address and prefix length.
     * @param list<ProxyHeader> $headers
     */
    private function __construct(
        private readonly array $list,
        private readonly array $networks,
        private readonly bool $all,
        private readonly array $headers = [ProxyHeader::XForwarded],
    ) {
    }

    /** Nobody: every forwarded header is ignored. */
    public static function none(): self
    {
        return new self([], [], false);
    }

    /**
     * @param string ...$proxies Addresses (`192.168.1.5`, `::1`), networks (`10.0.0.0/8`,
     *     `fd00::/8`), or `*` alone — the peer, whoever it is, is the proxy. None — nobody.
     * @throws \InvalidArgumentException A value that is neither, named in the message.
     */
    public static function of(string ...$proxies): self
    {
        $proxies = array_values($proxies);
        if (in_array('*', $proxies, true)) {
            if (count($proxies) > 1) {
                throw new \InvalidArgumentException(
                    "Trusted proxies: '*' already trusts everyone — give it alone, or give the addresses."
                );
            }
            return new self(['*'], [], true);
        }
        $networks = [];
        foreach ($proxies as $proxy) {
            $networks[] = self::network($proxy);
        }
        return new self($proxies, $networks, false);
    }

    /**
     * The same proxies, believed for these header families.
     *
     * @throws \InvalidArgumentException None given.
     */
    public function withHeaders(ProxyHeader ...$headers): self
    {
        if ($headers === []) {
            throw new \InvalidArgumentException(
                'Trusted headers: name at least one — to believe nobody, give no trusted proxies.'
            );
        }
        $unique = [];
        foreach ($headers as $header) {
            $unique[$header->value] = $header;
        }
        return new self($this->list, $this->networks, $this->all, array_values($unique));
    }

    /** Whether a request from this peer may say who its client is. */
    public function trusts(string $ip): bool
    {
        if ($this->all) {
            return true;
        }
        return $this->inList($ip);
    }

    /**
     * Whether a hop of a chain is one of the proxies. With `*` none is: only the peer is
     * known to be a proxy, and what it reports — the rightmost hop — is the client.
     */
    public function trustsHop(string $ip): bool
    {
        return !$this->all && $this->inList($ip);
    }

    /** Whether the proxy's headers of this family are read. */
    public function believes(ProxyHeader $header): bool
    {
        return in_array($header, $this->headers, true);
    }

    /** Whether `*` was given: the peer is a proxy, whoever it is. */
    public function trustsAll(): bool
    {
        return $this->all;
    }

    /** Whether no proxy is trusted at all. */
    public function isEmpty(): bool
    {
        return !$this->all && $this->networks === [];
    }

    /** @return list<string> What was given, in its order. */
    public function list(): array
    {
        return $this->list;
    }

    /** @return list<ProxyHeader> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The client in a chain of hops, as `X-Forwarded-For` lists them — the client first,
     * each proxy appending the address it got the request from. Only the right end was
     * written by proxies of ours; the left is whatever the client sent. So the chain is
     * read from the right, past every trusted proxy, and the first hop that is not one is
     * the client. Every hop trusted — the leftmost. A hop that is no address (`unknown`,
     * an obfuscated `_hidden`) ends the walk: what lies left of it was written by someone
     * unknown, so the client is not known.
     *
     * @param list<string> $hops
     * @return int|null The index of the client's hop; null — not known.
     */
    public function clientIndex(array $hops): ?int
    {
        $last = null;
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $ip = self::address($hops[$i]);
            if ($ip === null) {
                return null;
            }
            if (!$this->trustsHop($ip)) {
                return $i;
            }
            $last = $i;
        }
        return $last;
    }

    /**
     * @param list<string> $hops
     * @return string|null The client's address; null — not known.
     */
    public function client(array $hops): ?string
    {
        $i = $this->clientIndex($hops);
        return $i === null ? null : self::address($hops[$i]);
    }

    /**
     * A hop as an address, the way it is compared and reported: quotes, brackets, a port,
     * a zone cut off; an IPv4-mapped IPv6 address in any spelling as its IPv4. Null — it
     * is no address.
     */
    public static function address(string $hop): ?string
    {
        $value = trim($hop, " \t\"");
        if (str_starts_with($value, '[')) {
            $end = strpos($value, ']');
            $value = $end === false ? '' : substr($value, 1, $end - 1);
        } elseif (substr_count($value, ':') === 1) {
            $value = explode(':', $value)[0];          // 192.0.2.43:47011
        }
        $value = explode('%', $value)[0];              // fe80::9%eth0
        $packed = $value === '' ? false : @inet_pton($value);
        if ($packed === false) {
            return null;
        }
        $packed = self::unmap($packed);
        return (string) inet_ntop($packed);
    }

    /** @internal Remembered at boot, in the process every server is forked from. */
    public static function install(self $proxies): void
    {
        self::$installed = $proxies;
    }

    /** The proxies of the running application; nobody when none were installed. */
    public static function installed(): self
    {
        return self::$installed ?? self::none();
    }

    private function inList(string $ip): bool
    {
        if ($this->networks === []) {
            return false;
        }
        $address = self::address($ip);
        if ($address === null || str_contains(trim($ip), '%')) {
            return false;   // a peer is never written with a zone: fail closed
        }
        $packed = inet_pton($address);
        foreach ($this->networks as [$network, $bits]) {
            if (strlen($network) === strlen($packed) && self::inNetwork($packed, $network, $bits)) {
                return true;
            }
        }
        return false;
    }

    /** `::ffff:a.b.c.d` packed → `a.b.c.d` packed. */
    private static function unmap(string $packed): string
    {
        return strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")
            ? substr($packed, 12)
            : $packed;
    }

    /** @return array{string, int} */
    private static function network(string $proxy): array
    {
        $value = trim($proxy);
        [$address, $prefix] = str_contains($value, '/') ? explode('/', $value, 2) : [$value, null];
        $packed = $address !== '' && filter_var($address, FILTER_VALIDATE_IP) !== false
            ? inet_pton($address)
            : false;
        $max = $packed === false ? 0 : strlen($packed) * 8;
        $bits = $prefix === null ? $max : (ctype_digit($prefix) ? (int) $prefix : -1);
        if ($packed === false || $bits < 0 || $bits > $max) {
            throw new \InvalidArgumentException(
                "Trusted proxies: '{$proxy}' is not an address or a network — "
                . "give it as 10.0.0.5, 10.0.0.0/8, fd00::/8 or '*'."
            );
        }
        // ::ffff:10.0.0.0/104 is 10.0.0.0/8: a mapped network is an IPv4 one.
        if (strlen($packed) === 16 && $bits >= 96 && self::unmap($packed) !== $packed) {
            return [self::unmap($packed), $bits - 96];
        }
        return [$packed, $bits];
    }

    private static function inNetwork(string $packed, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (strncmp($packed, $network, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
