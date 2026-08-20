<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Response;

use Flytachi\Winter\Kernel\Http\Response\ContentType;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * What happens when a body cannot be encoded.
 *
 * `json_encode()` answers `false` on malformed UTF-8 — a byte from an external system, a
 * truncated multibyte character read out of a column — while this method is declared to
 * return a string. The TypeError that followed was true and useless: "Return value must
 * be of type string, false returned" sends the reader into the framework rather than to
 * the encoding of their data.
 *
 * The router catches it either way, so no worker dies; what changes here is whether the
 * 500 says anything worth reading.
 */
final class ContentTypeSerializeTest extends TestCase
{
    /** A lone continuation byte — invalid on its own. */
    private const string BROKEN = "\xB1\x31";

    public function test_malformed_utf8_raises_a_json_exception(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('Malformed UTF-8');

        ContentType::JSON->serialize(['name' => self::BROKEN]);
    }

    /** The failure used to be a TypeError about the return type, which said nothing. */
    public function test_the_failure_is_not_a_type_error(): void
    {
        try {
            ContentType::JSON->serialize(['name' => self::BROKEN]);
            self::fail('encoding should have failed');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\TypeError::class, $e);
        }
    }

    public function test_valid_utf8_still_encodes(): void
    {
        self::assertSame(
            '{"name":"Ann","город":"Ташкент"}',
            ContentType::JSON->serialize(['name' => 'Ann', 'город' => 'Ташкент']),
        );
    }

    /** Unicode and slashes stay unescaped — the flags this call has always carried. */
    public function test_unicode_and_slashes_are_left_alone(): void
    {
        self::assertSame(
            '{"url":"https://example.com/a"}',
            ContentType::JSON->serialize(['url' => 'https://example.com/a']),
        );
    }

    public function test_a_scalar_body_is_stringified_without_json(): void
    {
        self::assertSame('42', ContentType::TEXT->serialize(42));
        self::assertSame('plain', ContentType::TEXT->serialize('plain'));
    }
}
