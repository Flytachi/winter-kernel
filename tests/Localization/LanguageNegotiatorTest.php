<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Localization;

use Flytachi\Winter\K2\Localization\LanguageNegotiator;
use PHPUnit\Framework\TestCase;

final class LanguageNegotiatorTest extends TestCase
{
    public function test_returns_default_when_header_is_empty(): void
    {
        self::assertSame('en', LanguageNegotiator::negotiate('', ['en', 'ru'], 'en'));
    }

    public function test_returns_default_when_no_available_locales(): void
    {
        self::assertSame('en', LanguageNegotiator::negotiate('ru,en', [], 'en'));
    }

    public function test_picks_exact_match(): void
    {
        self::assertSame('ru', LanguageNegotiator::negotiate('ru', ['en', 'ru'], 'en'));
    }

    public function test_picks_highest_quality_match(): void
    {
        self::assertSame(
            'ru',
            LanguageNegotiator::negotiate('en;q=0.5,ru;q=0.9', ['en', 'ru'], 'en')
        );
    }

    public function test_default_quality_is_one(): void
    {
        self::assertSame(
            'ru',
            LanguageNegotiator::negotiate('ru,en;q=0.9', ['en', 'ru'], 'en')
        );
    }

    public function test_falls_back_to_base_locale(): void
    {
        // ru-RU not in list, but its base 'ru' is
        self::assertSame(
            'ru',
            LanguageNegotiator::negotiate('ru-RU', ['en', 'ru'], 'en')
        );
    }

    public function test_returns_default_when_nothing_matches(): void
    {
        self::assertSame(
            'en',
            LanguageNegotiator::negotiate('fr,de', ['en', 'ru'], 'en')
        );
    }

    public function test_real_world_chrome_header(): void
    {
        // Realistic Chrome Accept-Language string
        self::assertSame(
            'ru',
            LanguageNegotiator::negotiate(
                'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                ['en', 'ru'],
                'en'
            )
        );
    }
}
