<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Sse;

/**
 * One event of a Server-Sent Events stream ({@see SseEmitter}): what the browser's
 * `EventSource` gets as a `MessageEvent` — its `data`, its type (`event`, `message` when
 * none), its `id` (sent back as `Last-Event-ID` when the browser reconnects).
 *
 * A string goes as it is, anything else as JSON. Lines of data become `data:` lines.
 *
 * @link https://winterframe.net/docs/sse Server-Sent Events
 */
final readonly class SseEvent
{
    /** The data, as it goes on the wire. */
    public string $data;

    /**
     * @param mixed $data A string as it is; anything else is encoded as JSON.
     * @param string|null $event Its type: what `addEventListener()` listens for; null — `message`.
     * @param string|null $id What the browser sends back as `Last-Event-ID` after a reconnect.
     * @param int|null $retry Milliseconds the browser waits before it reconnects.
     * @throws \InvalidArgumentException A line break in `event` or `id`, a negative `retry`.
     * @throws \JsonException Data JSON cannot encode.
     */
    public function __construct(
        mixed $data = '',
        public ?string $event = null,
        public ?string $id = null,
        public ?int $retry = null,
    ) {
        foreach (['event' => $event, 'id' => $id] as $field => $value) {
            // NUL too: a browser drops an id holding it, and Last-Event-ID stays the old one.
            if ($value !== null && strpbrk($value, "\r\n\0") !== false) {
                throw new \InvalidArgumentException("An event's {$field} is one line of text.");
            }
        }
        if ($retry !== null && $retry < 0) {
            throw new \InvalidArgumentException('retry cannot be negative.');
        }
        $this->data = is_string($data)
            ? $data
            : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** The event in the text/event-stream format, its blank line included. */
    public function toWire(): string
    {
        $wire = '';
        if ($this->event !== null) {
            $wire .= "event: {$this->event}\n";
        }
        if ($this->id !== null) {
            $wire .= "id: {$this->id}\n";
        }
        if ($this->retry !== null) {
            $wire .= "retry: {$this->retry}\n";
        }
        foreach (preg_split('/\r\n|\r|\n/', $this->data) ?: [''] as $line) {
            $wire .= "data: {$line}\n";
        }
        return $wire . "\n";
    }
}
