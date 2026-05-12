<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\Constraint;
use Flytachi\Winter\K2\Http\Request\Validation\Date;
use Flytachi\Winter\K2\Http\Request\Validation\Datetime;
use Flytachi\Winter\K2\Http\Request\Validation\Digits;
use Flytachi\Winter\K2\Http\Request\Validation\Email;
use Flytachi\Winter\K2\Http\Request\Validation\In;
use Flytachi\Winter\K2\Http\Request\Validation\Ip;
use Flytachi\Winter\K2\Http\Request\Validation\Ipv4;
use Flytachi\Winter\K2\Http\Request\Validation\Ipv6;
use Flytachi\Winter\K2\Http\Request\Validation\Max;
use Flytachi\Winter\K2\Http\Request\Validation\Min;
use Flytachi\Winter\K2\Http\Request\Validation\Msisdn;
use Flytachi\Winter\K2\Http\Request\Validation\Negative;
use Flytachi\Winter\K2\Http\Request\Validation\NegativeOrZero;
use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use Flytachi\Winter\K2\Http\Request\Validation\Phone;
use Flytachi\Winter\K2\Http\Request\Validation\Positive;
use Flytachi\Winter\K2\Http\Request\Validation\PositiveOrZero;
use Flytachi\Winter\K2\Http\Request\Validation\Regex;
use Flytachi\Winter\K2\Http\Request\Validation\Required;
use Flytachi\Winter\K2\Http\Request\Validation\Size;
use Flytachi\Winter\K2\Http\Request\Validation\Time;
use Flytachi\Winter\K2\Http\Request\Validation\Url;
use Flytachi\Winter\K2\Http\Request\Validation\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cross-cutting test: every Constraint that exposes a `message:` parameter
 * must return that exact string instead of its default error message
 * when validation fails. Locks the i18n contract from regressing.
 */
final class CustomMessageTest extends TestCase
{
    private const MSG = '__CUSTOM__';

    #[DataProvider('failingConstraints')]
    public function test_custom_message_overrides_default(Constraint $constraint, mixed $value): void
    {
        self::assertSame(
            self::MSG,
            $constraint->validate($value, 'field'),
            $constraint::class . ' did not honor custom message'
        );
    }

    /**
     * @return iterable<string, array{Constraint, mixed}>
     */
    public static function failingConstraints(): iterable
    {
        yield 'Size'           => [new Size(3, message: self::MSG), 'too long'];
        yield 'Min'            => [new Min(10, message: self::MSG), 1];
        yield 'Max'            => [new Max(10, message: self::MSG), 11];
        yield 'Positive'       => [new Positive(message: self::MSG), -1];
        yield 'PositiveOrZero' => [new PositiveOrZero(message: self::MSG), -1];
        yield 'Negative'       => [new Negative(message: self::MSG), 1];
        yield 'NegativeOrZero' => [new NegativeOrZero(message: self::MSG), 1];
        yield 'NotBlank'       => [new NotBlank(message: self::MSG), '   '];
        yield 'Required'       => [new Required(message: self::MSG), null];
        yield 'Email'          => [new Email(message: self::MSG), 'not-an-email'];
        yield 'Url'            => [new Url(message: self::MSG), 'not-a-url'];
        yield 'Phone'          => [new Phone(message: self::MSG), 'abc'];
        yield 'Msisdn'         => [new Msisdn(message: self::MSG), '+abc'];
        yield 'Ip'             => [new Ip(message: self::MSG), 'bad'];
        yield 'Ipv4'           => [new Ipv4(message: self::MSG), '::1'];
        yield 'Ipv6'           => [new Ipv6(message: self::MSG), '127.0.0.1'];
        yield 'Uuid'           => [new Uuid(message: self::MSG), 'not-uuid'];
        yield 'Uuid v4'        => [new Uuid(4, message: self::MSG), '00000000-0000-1000-8000-000000000000'];
        yield 'In'             => [new In(['a', 'b'], message: self::MSG), 'c'];
        yield 'Regex'          => [new Regex('/^\d+$/', message: self::MSG), 'abc'];
        yield 'Date'           => [new Date(message: self::MSG), 'bad-date'];
        yield 'Datetime fmt'   => [new Datetime('Y-m-d H:i:s', message: self::MSG), 'bad-dt'];
        yield 'Datetime free'  => [new Datetime(message: self::MSG), 'bad-dt'];
        yield 'Time default'   => [new Time(message: self::MSG), 'bad-time'];
        yield 'Time fmt'       => [new Time('H:i', message: self::MSG), '14:30:00'];
        yield 'Digits int'     => [new Digits(2, message: self::MSG), 1234];
        yield 'Digits fract'   => [new Digits(integer: 5, fraction: 1, message: self::MSG), 1.234];
    }

    /**
     * Sanity check: every Constraint listed above must actually fail on the
     * provided value with the default message when no custom message is set.
     * Without this, a passing data row would silently masquerade as success.
     */
    #[DataProvider('failingConstraints')]
    public function test_value_actually_fails_without_custom_message(Constraint $constraint, mixed $value): void
    {
        // Re-instantiate without `message:` — same args minus the override.
        $clone = self::cloneWithoutMessage($constraint);
        self::assertNotNull(
            $clone->validate($value, 'field'),
            $clone::class . ': fixture value did not actually trigger a failure'
        );
    }

    private static function cloneWithoutMessage(Constraint $c): Constraint
    {
        $vars = get_object_vars($c);
        unset($vars['message']);
        return new ($c::class)(...$vars);
    }
}
