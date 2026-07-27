<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App;

/**
 * Parsed CLI arguments passed to {@see \Flytachi\Winter\K2\WinterApplication::run()}.
 *
 * Splits a raw `$argv` into three buckets:
 *   - positionals — bare words: `command` (argv[1]) and `sub` (argv[2]);
 *   - options     — long form `--key` or `--key=value`;
 *   - flags       — short form `-w`, `-abc` (expanded to a, b, c).
 *
 * Values layer over configuration as overrides, precedence: CLI > .env > default.
 * There are no Spring-style profiles; only real knobs (`--port`, `--host`, ...).
 */
final class ApplicationArguments
{
    /**
     * @param list<string> $raw Original argv (script name in [0]).
     * @param list<string> $positionals Bare words after the script name.
     * @param array<string, string|true> $options Long options (`true` = present, no value).
     * @param array<string, true> $flags Short flags.
     */
    private function __construct(
        private array $raw,
        private array $positionals,
        private array $options,
        private array $flags,
    ) {
    }

    /**
     * @param list<string> $argv Raw argv (script name in [0]).
     */
    public static function parse(array $argv): self
    {
        $tokens = array_slice(array_values($argv), 1);

        $positionals = [];
        $options = [];
        $flags = [];

        foreach ($tokens as $token) {
            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                if ($body === '') {
                    continue;
                }
                if (str_contains($body, '=')) {
                    [$key, $val] = explode('=', $body, 2);
                    $options[$key] = $val;
                } else {
                    $options[$body] = true;
                }
            } elseif (str_starts_with($token, '-') && $token !== '-') {
                foreach (str_split(substr($token, 1)) as $ch) {
                    $flags[$ch] = true;
                }
            } else {
                $positionals[] = $token;
            }
        }

        return new self(array_values($argv), $positionals, $options, $flags);
    }

    /** The command word (argv[1]) or null (bare invocation). */
    public function command(): ?string
    {
        return $this->positionals[0] ?? null;
    }

    /** The sub-command word (argv[2]) or null. */
    public function sub(): ?string
    {
        return $this->positionals[1] ?? null;
    }

    /** True if a long option `--key` was passed (with or without a value). */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->options);
    }

    /** True if a short flag `-x` was passed. */
    public function flag(string $char): bool
    {
        return isset($this->flags[$char]);
    }

    /** A long option's value, or $default if absent (or present without a value). */
    public function option(string $key, ?string $default = null): ?string
    {
        $value = $this->options[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    /** A long option parsed as int, or $default if absent / not numeric. */
    public function int(string $key, int $default): int
    {
        $value = $this->option($key);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    /** The original argv (to hand off to the console dispatcher). */
    public function raw(): array
    {
        return $this->raw;
    }
}
