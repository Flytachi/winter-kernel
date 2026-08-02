<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Mapping\Structure;

use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\NameValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameValidatorTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function validNames(): array
    {
        return [
            'lowercase'              => ['users'],
            'mixed case'             => ['UserProfile'],
            'snake'                  => ['user_profiles'],
            'leading underscore'     => ['_internal'],
            'digits trailing'        => ['users2024'],
            'single char letter'     => ['a'],
            'single char underscore' => ['_'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function invalidNames(): array
    {
        return [
            'leading digit'           => ['1users'],
            'dash'                    => ['user-profile'],
            'dot'                     => ['public.users'],
            'space'                   => ['user profile'],
            'quote'                   => ["user'name"],
            'special'                 => ['user@name'],
            'unicode-letter rejected' => ['пользователи'],
        ];
    }

    #[DataProvider('validNames')]
    public function test_valid_name_does_not_throw(string $name): void
    {
        NameValidator::validate($name);
        $this->expectNotToPerformAssertions();
    }

    public function test_empty_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Name cannot be empty.');
        NameValidator::validate('');
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_name_throws_with_format_message(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid name format/');
        NameValidator::validate($name);
    }

    public function test_too_long_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeds maximum length of 5/');
        NameValidator::validate('abcdef', 5);
    }

    public function test_default_max_length_is_63_pgsql_compatible(): void
    {
        // 63 chars — accepted
        NameValidator::validate(str_repeat('a', 63));
        // 64 chars — rejected
        $this->expectException(\InvalidArgumentException::class);
        NameValidator::validate(str_repeat('a', 64));
    }
}
