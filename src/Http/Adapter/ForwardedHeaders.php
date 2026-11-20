<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Adapter;

use Flytachi\Winter\Kernel\Http\TrustedProxies;
use Flytachi\Winter\Logger\LoggerFactory;

/**
 * What a request's proxy says about its client — read only when the request came from a
 * trusted proxy. Shared by the request adapters, so both runtimes believe the same.
 *
 * A request from anyone else that carries these headers is told about once per sender: it
 * is either a proxy missing from the list (after an upgrade, typically) or a client trying
 * to pass for someone else.
 *
 * @internal
 */
final class ForwardedHeaders
{
    /** Senders warned about per worker; past that, one line says the rest are not. */
    public const int WARN_PEERS = 32;

    /** @var array<string, string> The headers it reads, by their lower-case name. */
    private const array NAMES = [
        'forwarded' => 'Forwarded',
        'x-forwarded-for' => 'X-Forwarded-For',
        'x-real-ip' => 'X-Real-IP',
        'x-forwarded-proto' => 'X-Forwarded-Proto',
        'x-forwarded-host' => 'X-Forwarded-Host',
        'x-forwarded-port' => 'X-Forwarded-Port',
    ];

    /** @var array<string, true> Senders already warned about in this worker. */
    private static array $warned = [];

    /** @var array<string, string> Present headers, only when the sender is trusted. */
    private readonly array $headers;

    /**
     * @param array<string, string> $headers The request's headers by lower-case name.
     * @param string $peer The address the connection came from.
     */
    public function __construct(
        array $headers,
        private readonly string $peer,
        private readonly TrustedProxies $proxies,
    )
    {
        $present = [];
        foreach (self::NAMES as $name => $_) {
            if (isset($headers[$name]) && $headers[$name] !== '') {
                $present[$name] = (string) $headers[$name];
            }
        }
        if ($present !== [] && !$proxies->trusts($peer)) {
            self::warn($peer, $present, $proxies);
            $present = [];
        }
        $this->headers = $present;
    }

    /** The client's address: what a trusted proxy says, else the connection's. */
    public function clientIp(): string
    {
        $h = $this->headers;
        $ip = null;
        if (isset($h['forwarded'])) {
            $ip = $this->proxies->client(self::forwardedFor($h['forwarded']));
        }
        if ($ip === null && isset($h['x-real-ip']) && filter_var(trim($h['x-real-ip']), FILTER_VALIDATE_IP) !== false) {
            $ip = trim($h['x-real-ip']);
        }
        if ($ip === null && isset($h['x-forwarded-for'])) {
            $ip = $this->proxies->client(explode(',', $h['x-forwarded-for']));
        }
        return ClientAddress::unmapped($ip ?? $this->peer);
    }

    /** `http` or `https` as the proxy saw it; null — no trusted word on it. */
    public function proto(): ?string
    {
        $proto = null;
        if (isset($this->headers['forwarded']) && preg_match('/proto=([A-Za-z]+)/i', $this->headers['forwarded'], $m)) {
            $proto = strtolower($m[1]);
        } elseif (isset($this->headers['x-forwarded-proto'])) {
            $proto = strtolower(trim(explode(',', $this->headers['x-forwarded-proto'])[0]));
        }
        return $proto === 'http' || $proto === 'https' ? $proto : null;
    }

    /** The host the client asked for, maybe with a port; null — no trusted word on it. */
    public function host(): ?string
    {
        if (isset($this->headers['forwarded']) && preg_match('/host=([^;,\s]+)/i', $this->headers['forwarded'], $m)) {
            return trim($m[1], '"\'');
        }
        if (isset($this->headers['x-forwarded-host'])) {
            return trim(explode(',', $this->headers['x-forwarded-host'])[0]);
        }
        return null;
    }

    /** The port the client dialled; null — no trusted word on it. */
    public function port(): ?int
    {
        if (!isset($this->headers['x-forwarded-port'])) {
            return null;
        }
        $port = (int) trim(explode(',', $this->headers['x-forwarded-port'])[0]);
        return $port > 0 ? $port : null;
    }

    /** Whether a trusted proxy sent it: then the server's own port is the proxy's hop. */
    public function proxied(): bool
    {
        return isset($this->headers['forwarded'])
            || isset($this->headers['x-forwarded-proto'])
            || isset($this->headers['x-forwarded-host'])
            || isset($this->headers['x-forwarded-for']);
    }

    /** @internal For tests: forget whom this worker warned about. */
    public static function resetWarnings(): void
    {
        self::$warned = [];
    }

    /**
     * The `for=` of every element, client first: `for=1.2.3.4, for="[2001:db8::7]:4711"`.
     *
     * @return list<string>
     */
    private static function forwardedFor(string $header): array
    {
        $hops = [];
        foreach (explode(',', $header) as $element) {
            if (preg_match('/(?:^|;)\s*for=("[^"]*"|[^;,\s]+)/i', $element, $m) !== 1) {
                continue;
            }
            $value = trim($m[1], '"');
            if (str_starts_with($value, '[')) {
                $value = substr($value, 1, (int) strpos($value, ']') - 1);
            } elseif (substr_count($value, ':') === 1) {
                $value = explode(':', $value)[0];   // 192.0.2.43:47011
            }
            $hops[] = $value;
        }
        return $hops;
    }

    /** @param array<string, string> $present */
    private static function warn(string $peer, array $present, TrustedProxies $proxies): void
    {
        $peer = self::clean($peer);
        if (isset(self::$warned[$peer]) || count(self::$warned) > self::WARN_PEERS) {
            return;
        }
        $logger = LoggerFactory::getLogger(TrustedProxies::class);
        if (count(self::$warned) === self::WARN_PEERS) {
            self::$warned[''] = true;
            $logger->warning(sprintf(
                'Forwarded headers from %d senders were ignored; further senders are no longer logged in this worker.',
                self::WARN_PEERS,
            ));
            return;
        }
        self::$warned[$peer] = true;

        $names = implode(', ', array_map(static fn(string $n): string => self::NAMES[$n], array_keys($present)));
        $why = $proxies->isEmpty()
            ? "no proxy is trusted. If {$peer} is your proxy, trust it: "
                . "\$server->trustedProxies('{$peer}') in configureServer()."
            : 'it is not a trusted proxy (' . implode(', ', $proxies->list()) . '): '
                . 'a client sending them past the proxy, or a proxy missing from the list.';
        $logger->warning(
            "Forwarded headers from {$peer} ignored ({$names}): {$why}",
            array_map(self::clean(...), $present),
        );
    }

    /** A value the client chose, made safe for one log line. */
    private static function clean(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
        return mb_strlen($value) > 120 ? mb_substr($value, 0, 120) . '…' : $value;
    }
}
