<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Sse;

use Flytachi\Winter\Kernel\Http\Sse\SseEvent;
use PHPUnit\Framework\TestCase;

/** One event as it goes over the wire: the text/event-stream format. */
final class SseEventTest extends TestCase
{
    public function test_data_alone_is_a_message(): void
    {
        self::assertSame("data: hello\n\n", new SseEvent('hello')->toWire());
    }

    public function test_every_field_in_its_order(): void
    {
        self::assertSame(
            "event: message\nid: 42\nretry: 3000\ndata: hello\n\n",
            new SseEvent('hello', event: 'message', id: '42', retry: 3000)->toWire(),
        );
    }

    public function test_lines_of_data_are_lines_of_data(): void
    {
        self::assertSame("data: one\ndata: two\ndata: three\ndata: \n\n", new SseEvent("one\ntwo\r\nthree\r")->toWire());
    }

    public function test_an_array_is_json(): void
    {
        self::assertSame(
            "data: {\"id\":7,\"name\":\"Зима\",\"url\":\"/a/b\"}\n\n",
            new SseEvent(['id' => 7, 'name' => 'Зима', 'url' => '/a/b'])->toWire(),
        );
    }

    public function test_an_empty_message_still_ends_with_its_blank_line(): void
    {
        self::assertSame("data: \n\n", new SseEvent()->toWire());
    }

    public function test_a_line_break_in_the_name_or_id_is_refused(): void
    {
        foreach ([['event' => "a\nb"], ['id' => "1\r"], ['id' => "1\0"], ['retry' => -1]] as $bad) {
            try {
                new SseEvent('x', ...$bad);
                self::fail('accepted: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_data_json_cannot_take_is_refused(): void
    {
        $this->expectException(\JsonException::class);
        new SseEvent(["\xB1\x31"]);
    }
}
