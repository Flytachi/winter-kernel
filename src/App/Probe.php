<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * How the application checks that an outside program is still alive once it is ready —
 * the `alive` of {@see Component::command()}: a command that exits 0 while the program
 * is fine, or a port that accepts a connection.
 *
 * Asked every `$every` seconds; a check that has not answered within `$timeout` seconds
 * counts as a failure; `$failures` failures in a row mean the program hangs, and it is
 * stopped — its stop signal, then SIGTERM, then SIGKILL — and restarted as one that
 * crashed. One check that passes resets the count.
 *
 * The defaults are cautious: a program is taken for hung after about a minute without a
 * single answer, so a busy server — a long export, a checkpoint — is not killed for it.
 *
 * @link https://winterframe.net/docs/components#alive Is it still alive
 */
final readonly class Probe
{
    /** Seconds between checks. */
    public const float EVERY = 10.0;
    /** Seconds one check may take. */
    public const float TIMEOUT = 5.0;
    /** Failed checks in a row that mean the program hangs. */
    public const int FAILURES = 6;

    private const string TCP = 'tcp://';

    /**
     * @param list<string> $command The check, when it is a command.
     * @param string|null $address `tcp://host:port`, when it is a port.
     */
    private function __construct(
        public array $command,
        public ?string $address,
        public float $every,
        public float $timeout,
        public int $failures,
    ) {
        if ($every <= 0.0 || $timeout <= 0.0) {
            throw new \InvalidArgumentException('a probe needs every and timeout above 0.');
        }
        if ($failures < 1) {
            throw new \InvalidArgumentException("a probe needs at least 1 failure to act on, {$failures} given.");
        }
    }

    /**
     * A command that exits 0 while the program is fine: `['pg_isready', '-q']`. Its
     * output is discarded.
     *
     * @param list<string> $command The program and its arguments.
     * @param float $every Seconds between checks.
     * @param float $timeout Seconds one check may take; longer is a failure.
     * @param int $failures Failed checks in a row that mean the program hangs.
     */
    public static function command(
        array $command,
        float $every = self::EVERY,
        float $timeout = self::TIMEOUT,
        int $failures = self::FAILURES,
    ): self {
        self::assertCommand($command);
        return new self(array_values($command), null, $every, $timeout, $failures);
    }

    /**
     * A port that accepts a connection while the program is fine: `127.0.0.1:6379`
     * (`tcp://` may be written or left out).
     *
     * @param float $every Seconds between checks.
     * @param float $timeout Seconds one check may take; longer is a failure.
     * @param int $failures Failed checks in a row that mean the program hangs.
     */
    public static function tcp(
        string $address,
        float $every = self::EVERY,
        float $timeout = self::TIMEOUT,
        int $failures = self::FAILURES,
    ): self {
        $address = str_starts_with($address, self::TCP) ? $address : self::TCP . $address;
        self::assertAddress($address);
        return new self([], $address, $every, $timeout, $failures);
    }

    /**
     * A probe from what `alive` was given: a Probe as it is; a command or an address
     * with the default timing.
     *
     * @param list<string>|string|self $check
     */
    public static function of(array|string|self $check): self
    {
        return match (true) {
            $check instanceof self => $check,
            is_string($check) => self::tcp($check),
            default => self::command($check),
        };
    }

    /**
     * @param list<string> $command
     * @throws \InvalidArgumentException Not a non-empty list of strings.
     */
    public static function assertCommand(array $command): void
    {
        $strings = array_filter($command, static fn($part): bool => is_string($part)) === $command;
        if ($command === [] || !$strings || !array_is_list($command) || $command[0] === '') {
            throw new \InvalidArgumentException(
                'a check command is a non-empty list of strings: the program, then its arguments.'
            );
        }
    }

    /** @throws \InvalidArgumentException Not `tcp://host:port`. */
    public static function assertAddress(string $address): void
    {
        $at = strrpos($address, ':');
        $port = $at === false ? '' : substr($address, $at + 1);
        $host = $at === false ? '' : substr($address, strlen(self::TCP), $at - strlen(self::TCP));
        $valid = str_starts_with($address, self::TCP) && $host !== '' && ctype_digit($port)
            && (int) $port >= 1 && (int) $port <= 65535;
        if (!$valid) {
            throw new \InvalidArgumentException("a check address is tcp://host:port, \"{$address}\" given.");
        }
    }
}
