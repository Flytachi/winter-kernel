<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Adapter;

use Flytachi\Winter\Kernel\Http\ProxyHeader;
use Flytachi\Winter\Kernel\Http\TrustedProxies;
use Flytachi\Winter\Logger\LoggerFactory;

/**
 * What a request's proxy says about its client — read only when the request came from a
 * trusted proxy, and only from the header families it is trusted for. Shared by the
 * request adapters, so both runtimes believe the same.
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

    /** @var array<string, array{string, ProxyHeader}> Lower-case name → its spelling and family. */
    private const array NAMES = [
        'forwarded' => ['Forwarded', ProxyHeader::Forwarded],
        'x-forwarded-for' => ['X-Forwarded-For', ProxyHeader::XForwarded],
        'x-real-ip' => ['X-Real-IP', ProxyHeader::XRealIp],
        'x-forwarded-proto' => ['X-Forwarded-Proto', ProxyHeader::XForwarded],
        'x-forwarded-host' => ['X-Forwarded-Host', ProxyHeader::XForwarded],
        'x-forwarded-port' => ['X-Forwarded-Port', ProxyHeader::XForwarded],
    ];

    /** @var array<string, true> Senders already warned about in this worker. */
    private static array $warned = [];

    /** @var array<string, string> Believed headers: a trusted sender's, of its trusted families. */
    private readonly array $headers;

    /** @var array{0: string|null, 1: array<string, string>}|null The client and its `Forwarded` element, once read. */
    private ?array $forwarded = null;

    /**
     * @param array<string, string> $headers The request's headers by lower-case name.
     * @param string $peer The address the connection came from.
     */
    public function __construct(
        array $headers,
        private readonly string $peer,
        private readonly TrustedProxies $proxies,
    ) {
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
        foreach ($present as $name => $_) {
            if (!$proxies->believes(self::NAMES[$name][1])) {
                unset($present[$name]);
            }
        }
        $this->headers = $present;
    }

    /** The client's address: what a trusted proxy says, else the connection's. */
    public function clientIp(): string
    {
        $h = $this->headers;
        $ip = null;
        if (isset($h['x-forwarded-for'])) {
            $ip = $this->proxies->client(explode(',', $h['x-forwarded-for']));
        }
        if ($ip === null && isset($h['forwarded'])) {
            $ip = $this->forwarded()[0];
        }
        if ($ip === null && isset($h['x-real-ip'])) {
            $real = TrustedProxies::address($h['x-real-ip']);
            // A proxy of ours named there is a hop, not the client.
            $ip = $real !== null && !$this->proxies->trustsHop($real) ? $real : null;
        }
        return ClientAddress::unmapped($ip ?? $this->peer);
    }

    /** `http` or `https` as the proxy saw it; null — no trusted word on it. */
    public function proto(): ?string
    {
        $proto = null;
        if (isset($this->headers['x-forwarded-proto'])) {
            $proto = strtolower(trim(explode(',', $this->headers['x-forwarded-proto'])[0]));
        } elseif (isset($this->headers['forwarded'])) {
            $proto = strtolower($this->forwarded()[1]['proto'] ?? '');
        }
        return $proto === 'http' || $proto === 'https' ? $proto : null;
    }

    /** The host the client asked for, maybe with a port; null — no trusted word on it. */
    public function host(): ?string
    {
        if (isset($this->headers['x-forwarded-host'])) {
            $host = trim(explode(',', $this->headers['x-forwarded-host'])[0]);
            return $host === '' ? null : $host;
        }
        if (isset($this->headers['forwarded'])) {
            $host = $this->forwarded()[1]['host'] ?? '';
            return $host === '' ? null : $host;
        }
        return null;
    }

    /** The port the client dialled; null — no trusted word on it. */
    public function port(): ?int
    {
        if (!isset($this->headers['x-forwarded-port'])) {
            return null;
        }
        $value = trim(explode(',', $this->headers['x-forwarded-port'])[0]);
        $port = ctype_digit($value) ? (int) $value : 0;
        return $port >= 1 && $port <= 65535 ? $port : null;
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
     * The client in `Forwarded`, and the element it is in — whose `proto` and `host` are
     * what the client asked for. The elements a client wrote itself, left of it, are not read.
     * With no client to be found, the rightmost element — the one our proxy wrote.
     *
     * @return array{0: string|null, 1: array<string, string>}
     */
    private function forwarded(): array
    {
        if ($this->forwarded !== null) {
            return $this->forwarded;
        }
        $elements = self::parseForwarded($this->headers['forwarded'] ?? '');
        $hops = array_map(static fn(array $e): string => $e['for'] ?? '', $elements);
        $i = $this->proxies->clientIndex($hops);
        // No client to be found (no `for`, an obfuscated one): the element our proxy
        // appended — the rightmost — still says how the request reached it.
        $element = $i !== null ? $elements[$i] : ($elements === [] ? [] : $elements[array_key_last($elements)]);
        return $this->forwarded = [$i === null ? null : TrustedProxies::address($hops[$i]), $element];
    }

    /**
     * `for=1.2.3.4;proto=https, for="[2001:db8::7]:4711"` → one map per element, keys in
     * lower case, values unquoted. A comma or semicolon inside quotes does not split.
     *
     * @return list<array<string, string>>
     */
    private static function parseForwarded(string $header): array
    {
        $elements = [];
        $pairs = [];
        $token = '';
        $quoted = false;
        $length = strlen($header);
        for ($i = 0; $i <= $length; $i++) {
            $c = $i < $length ? $header[$i] : ',';
            if ($c === '"') {
                $quoted = !$quoted;
                $token .= $c;
                continue;
            }
            if ($quoted || ($c !== ';' && $c !== ',')) {
                $token .= $c;
                continue;
            }
            if (str_contains($token, '=')) {
                [$key, $value] = explode('=', $token, 2);
                $value = trim($value);
                if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
                    $value = stripslashes(substr($value, 1, -1));
                }
                $pairs[strtolower(trim($key))] = $value;
            }
            $token = '';
            if ($c === ',') {
                $elements[] = $pairs;
                $pairs = [];
            }
        }
        return $elements;
    }

    /** @param array<string, string> $present */
    private static function warn(string $peer, array $present, TrustedProxies $proxies): void
    {
        $peer = self::clean($peer);
        if (isset(self::$warned[$peer]) || count(self::$warned) > self::WARN_PEERS) {
            return;
        }
        // Marked before the logger is asked for: its first creation may yield to another
        // request from the same sender.
        if (count(self::$warned) === self::WARN_PEERS) {
            self::$warned[''] = true;
            LoggerFactory::getLogger(TrustedProxies::class)->warning(sprintf(
                'Forwarded headers from %d senders were ignored; further senders are no longer logged in this worker.',
                self::WARN_PEERS,
            ));
            return;
        }
        self::$warned[$peer] = true;

        $names = implode(', ', array_map(static fn(string $n): string => self::NAMES[$n][0], array_keys($present)));
        $why = $proxies->isEmpty()
            ? "no proxy is trusted. If {$peer} is your proxy, trust it: "
                . "\$server->trustedProxies('{$peer}') in configureServer()."
            : 'it is not a trusted proxy (' . implode(', ', $proxies->list()) . '): '
                . 'a client sending them past the proxy, or a proxy missing from the list.';
        LoggerFactory::getLogger(TrustedProxies::class)->warning(
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
