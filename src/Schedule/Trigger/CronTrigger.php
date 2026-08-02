<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Schedule\Trigger;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Fires on a clock-aligned schedule described by a standard five-field cron
 * expression: `minute hour day-of-month month day-of-week`.
 *
 * Each field accepts a star, a single number, an `a-b` range, a step suffix
 * (`/s` after a star or a range), and comma-separated lists of those. Day-of-week
 * is `0-6` with Sunday `0` (also `7`). When both day-of-month and day-of-week are
 * restricted the match is their union (the Vixie-cron rule); when one is a star
 * only the other constrains the day.
 * The common macros are supported: `@yearly`/`@annually`, `@monthly`, `@weekly`,
 * `@daily`/`@midnight`, `@hourly`.
 *
 * Times are the server's local timezone. Firing happens on the minute, strictly
 * after the previous fire, so a run is never doubled within its minute.
 *
 * ```
 * new CronTrigger('0 2 * * *')     // every day at 02:00
 * new CronTrigger('0 8 * * 1-5')   // 08:00 on weekdays
 * new CronTrigger('* * * * *')     // every minute, on the minute
 * new CronTrigger('0 * * * *')     // every hour, on the hour
 * ```
 */
final class CronTrigger implements Trigger
{
    /** Safety bound on the calendar walk when resolving the next match. */
    private const int MAX_STEPS = 100_000;

    private const array MACROS = [
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    ];

    /** @var array<int, true> */
    private array $minutes;
    /** @var array<int, true> */
    private array $hours;
    /** @var array<int, true> */
    private array $daysOfMonth;
    /** @var array<int, true> */
    private array $months;
    /** @var array<int, true> */
    private array $daysOfWeek;
    private bool $domRestricted;
    private bool $dowRestricted;

    /**
     * @param string $expression A five-field cron expression or a supported macro.
     * @throws InvalidArgumentException On a malformed expression.
     */
    public function __construct(private readonly string $expression)
    {
        $normalized = self::MACROS[strtolower(trim($expression))] ?? trim($expression);
        $fields = preg_split('/\s+/', $normalized) ?: [];
        if (count($fields) !== 5) {
            throw new InvalidArgumentException(
                "cron '{$expression}' must have 5 fields (minute hour day month weekday) or be a macro."
            );
        }

        [$min, $hour, $dom, $mon, $dow] = $fields;
        $this->minutes = $this->parseField($min, 0, 59, $expression);
        $this->hours = $this->parseField($hour, 0, 23, $expression);
        $this->daysOfMonth = $this->parseField($dom, 1, 31, $expression);
        $this->months = $this->parseField($mon, 1, 12, $expression);
        $this->daysOfWeek = $this->normalizeWeekdays($this->parseField($dow, 0, 7, $expression));
        $this->domRestricted = trim($dom) !== '*';
        $this->dowRestricted = trim($dow) !== '*';
    }

    /**
     * {@inheritDoc}
     */
    public function firstFireTime(float $now, float $initialDelay): float
    {
        // Cron is clock-aligned; the initial delay does not apply.
        return $this->nextFireTime($now, null, null);
    }

    /**
     * {@inheritDoc}
     */
    public function nextFireTime(float $now, ?float $lastStartAt, ?float $lastEndAt): float
    {
        // Start at the next whole minute strictly after now, then walk the calendar
        // jumping over whole non-matching months/days/hours so even a rare rule
        // (e.g. Feb 29) resolves in a handful of steps.
        $t = new DateTimeImmutable()->setTimestamp((int) floor($now));
        $t = $t->setTime((int) $t->format('G'), (int) $t->format('i'), 0)->modify('+1 minute');

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            // Jump over a whole non-matching month or day; step minute by minute
            // inside a matching day (at most 1440 steps, so hour need not jump).
            if (!isset($this->months[(int) $t->format('n')])) {
                $t = $t->modify('first day of next month')->setTime(0, 0, 0);
                continue;
            }
            if (!$this->dayMatches($t)) {
                $t = $t->modify('+1 day')->setTime(0, 0, 0);
                continue;
            }
            if (isset($this->hours[(int) $t->format('G')]) && isset($this->minutes[(int) $t->format('i')])) {
                return (float) $t->getTimestamp();
            }
            $t = $t->modify('+1 minute');
        }

        throw new InvalidArgumentException("cron '{$this->expression}' has no upcoming match.");
    }

    /**
     * {@inheritDoc}
     */
    public function describe(): string
    {
        return 'cron ' . $this->expression;
    }

    /**
     * Whether the date's day satisfies the day-of-month / day-of-week rule: their
     * union when both are restricted, otherwise whichever one constrains.
     */
    private function dayMatches(DateTimeImmutable $t): bool
    {
        $domOk = isset($this->daysOfMonth[(int) $t->format('j')]);
        $dowOk = isset($this->daysOfWeek[(int) $t->format('w')]);

        if ($this->domRestricted && $this->dowRestricted) {
            return $domOk || $dowOk;
        }
        if ($this->domRestricted) {
            return $domOk;
        }
        if ($this->dowRestricted) {
            return $dowOk;
        }
        return true;
    }

    /**
     * Parses one cron field into the set of values it allows.
     *
     * @return array<int, true>
     */
    private function parseField(string $field, int $min, int $max, string $expression): array
    {
        $allowed = [];
        foreach (explode(',', trim($field)) as $part) {
            $step = 1;
            $range = $part;
            if (str_contains($part, '/')) {
                [$range, $stepStr] = explode('/', $part, 2);
                if (!ctype_digit($stepStr) || (int) $stepStr < 1) {
                    throw new InvalidArgumentException("cron '{$expression}' has an invalid step in '{$part}'.");
                }
                $step = (int) $stepStr;
            }

            if ($range === '*') {
                $lo = $min;
                $hi = $max;
            } elseif (str_contains($range, '-')) {
                [$a, $b] = explode('-', $range, 2);
                $lo = $this->intOrFail($a, $part, $expression);
                $hi = $this->intOrFail($b, $part, $expression);
            } else {
                $lo = $hi = $this->intOrFail($range, $part, $expression);
            }

            if ($lo < $min || $hi > $max || $lo > $hi) {
                throw new InvalidArgumentException(
                    "cron '{$expression}' field '{$part}' is out of range [{$min}-{$max}]."
                );
            }
            for ($v = $lo; $v <= $hi; $v += $step) {
                $allowed[$v] = true;
            }
        }
        return $allowed;
    }

    /**
     * Folds weekday 7 (an accepted alias for Sunday) onto 0.
     *
     * @param array<int, true> $days
     * @return array<int, true>
     */
    private function normalizeWeekdays(array $days): array
    {
        if (isset($days[7])) {
            unset($days[7]);
            $days[0] = true;
        }
        return $days;
    }

    private function intOrFail(string $value, string $part, string $expression): int
    {
        if (!ctype_digit($value)) {
            throw new InvalidArgumentException("cron '{$expression}' field '{$part}' is not numeric.");
        }
        return (int) $value;
    }
}
