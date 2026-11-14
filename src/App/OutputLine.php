<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

use Psr\Log\LogLevel;

/**
 * What one line of an outside program's output means ({@see ProgramOutput}): an entry of
 * its log, a line that continues the one before, or nothing worth logging.
 */
final readonly class OutputLine
{
    private const array LEVELS = [
        LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING,
        LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY,
    ];

    /**
     * @param string|null $level A PSR-3 level; null: it continues the entry before.
     * @param array<string, mixed> $context
     */
    private function __construct(
        public ?string $level,
        public string $message,
        public array $context = [],
        public ?int $pid = null,
        public ?string $key = null,
        public bool $skip = false,
    ) {
    }

    /**
     * A line that starts an entry.
     *
     * @param string $level A PSR-3 level: `LogLevel::ERROR`, `'warning'`.
     * @param array<string, mixed> $context
     * @param int|null $pid The program's process that wrote it — a database's backend;
     *     the program's own PID by default.
     * @throws \InvalidArgumentException The level is not a PSR-3 one.
     */
    public static function entry(string $level, string $message, array $context = [], ?int $pid = null): self
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException("{$level} is not a PSR-3 log level.");
        }
        return new self($level, $message, $context, $pid);
    }

    /**
     * A line that continues the entry before: a detail of it, or the rest of a long
     * message. With no entry before it, it is an entry of its own, at INFO.
     *
     * @param string|null $key Where it goes: under this key in the entry's context, after
     *     what is there already. Null: on a new line of what the entry's lines went to
     *     last — the message, or the key of the line before.
     */
    public static function more(string $text, ?string $key = null): self
    {
        return new self(null, $text, key: $key);
    }

    /** A line not worth logging. */
    public static function skip(): self
    {
        return new self(null, '', skip: true);
    }
}
