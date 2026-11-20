<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

use Flytachi\Winter\Kernel\Http\Adapter\ClientAddress;

/**
 * The proxies whose word about the client is believed: `Forwarded`, `X-Forwarded-For`,
 * `X-Real-IP`, `X-Forwarded-Proto`, `X-Forwarded-Host` and `X-Forwarded-Port` are read
 * only on a request that came from one of them. From anyone else they are what the client
 * typed, and the connection's own address, scheme and host are used instead.
 *
 * Nobody is trusted until the application says so, in
 * {@see \Flytachi\Winter\Kernel\App\Config\ServerSettings::trustedProxies()}: a service
 * reachable past its proxy — a NodePort, a published container port — would otherwise
 * take any `X-Real-IP` a client sends for the client's address.
 *
 * @link https://winterframe.net/docs/web-configuration Trusted proxies
 */
final class TrustedProxies
{
    /**
     * @param list<string> $list As given, for the boot log.
     * @param list<array{string, int}> $networks Packed address and prefix length.
     */
    private function __construct(
        private readonly array $list,
        private readonly array $networks,
        private readonly bool $all,
    ) {
    }

    /** Nobody: every forwarded header is ignored. */
    public static function none(): self
    {
        return new self([], [], false);
    }

    /**
     * @param string ...$proxies Addresses (`192.168.1.5`, `::1`), networks (`10.0.0.0/8`,
     *     `fd00::/8`), or `*` alone — every peer is a proxy. None — nobody.
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

    /** Whether a request from this peer may say who its client is. */
    public function trusts(string $ip): bool
    {
        if ($this->all) {
            return true;
        }
        if ($this->networks === []) {
            return false;
        }
        $packed = @inet_pton(ClientAddress::unmapped(trim($ip, '[]')));
        if ($packed === false) {
            return false;
        }
        foreach ($this->networks as [$network, $bits]) {
            if (strlen($network) === strlen($packed) && self::inNetwork($packed, $network, $bits)) {
                return true;
            }
        }
        return false;
    }

    /** Whether `*` was given: every peer is a proxy. */
    public function trustsAll(): bool
    {
        return $this->all;
    }

    /** Whether any proxy is trusted at all. */
    public function isEmpty(): bool
    {
        return !$this->all && $this->networks === [];
    }

    /** @return list<string> What was given, in its order. */
    public function list(): array
    {
        return $this->list;
    }

    /**
     * The client in a chain of hops, as `X-Forwarded-For` lists them — the client first,
     * each proxy appending the address it got the request from. Only the right end was
     * written by proxies of ours; the left is whatever the client sent. So the chain is
     * read from the right, past every trusted proxy, and the first hop that is not one is
     * the client. Every hop trusted — the leftmost. A hop that is no address is passed over.
     *
     * @param list<string> $hops
     * @return string|null Null when no hop is an address.
     */
    public function client(array $hops): ?string
    {
        $last = null;
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $ip = ClientAddress::unmapped(trim($hops[$i], " \t\"[]"));
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!$this->trusts($ip)) {
                return $ip;
            }
            $last = $ip;
        }
        return $last;
    }

    /** @return array{string, int} */
    private static function network(string $proxy): array
    {
        $value = trim($proxy);
        [$address, $prefix] = str_contains($value, '/') ? explode('/', $value, 2) : [$value, null];
        $packed = $address !== '' && filter_var($address, FILTER_VALIDATE_IP) !== false
            ? inet_pton(ClientAddress::unmapped($address))
            : false;
        $max = $packed === false ? 0 : strlen($packed) * 8;
        $bits = $prefix === null ? $max : (ctype_digit($prefix) ? (int) $prefix : -1);
        if ($packed === false || $bits < 0 || $bits > $max) {
            throw new \InvalidArgumentException(
                "Trusted proxies: '{$proxy}' is not an address or a network — "
                . "give it as 10.0.0.5, 10.0.0.0/8, fd00::/8 or '*'."
            );
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
