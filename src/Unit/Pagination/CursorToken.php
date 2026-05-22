<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

/**
 * Internal helper for encoding / decoding {@see Paginator::cursor()} tokens.
 *
 * Wire format: `base64(json({"s": "<signature>", "v": [...values], "d": "<f|b>"}))`.
 *
 * - `s` — {@see CursorKey::signature()} at issue time; checked on decode to
 *   detect key-shape mismatches (redeploy / tampering / cross-endpoint reuse).
 * - `v` — cursor position values in `CursorKey::flatten()` order.
 * - `d` — {@see CursorDirection} (`f` = forward / after, `b` = backward / before).
 *
 * Embedding direction into the token lets the paginator accept a single
 * `$cursor` argument and derive navigation direction from the token itself —
 * the client just echoes back the cursor they want to follow.
 *
 * Not intended for direct use outside the pagination unit.
 */
final class CursorToken
{
    /**
     * Encodes a cursor token.
     *
     * @param string $signature Shape signature from {@see CursorKey::signature()}.
     * @param list<mixed> $values Cursor position values, in `CursorKey::flatten()` order.
     * @param CursorDirection $direction Navigation direction this token represents.
     */
    public static function encode(string $signature, array $values, CursorDirection $direction): string
    {
        $payload = json_encode(
            ['s' => $signature, 'v' => $values, 'd' => $direction->value],
            JSON_THROW_ON_ERROR,
        );
        return base64_encode($payload);
    }

    /**
     * Decodes a cursor token and verifies its signature against the expected one.
     *
     * @param string $token Base64-encoded JSON envelope.
     * @param string $expectedSignature Signature the current `CursorKey` produces.
     * @return array{0: list<mixed>, 1: CursorDirection} Tuple of [values, direction].
     * @throws InvalidCursorException When the token is malformed, missing fields,
     *                                or signature does not match `$expectedSignature`.
     */
    public static function decode(string $token, string $expectedSignature): array
    {
        $raw = base64_decode($token, strict: true);
        if ($raw === false) {
            throw new InvalidCursorException('Cursor is not valid base64.');
        }

        try {
            $payload = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidCursorException('Cursor payload is not valid JSON.', previous: $e);
        }

        if (
            !is_array($payload)
            || !isset($payload['s'], $payload['v'], $payload['d'])
            || !is_array($payload['v'])
            || !is_string($payload['d'])
        ) {
            throw new InvalidCursorException('Cursor payload is missing required fields (s, v, d).');
        }

        if ($payload['s'] !== $expectedSignature) {
            throw new InvalidCursorException(
                'Cursor signature mismatch — the cursor was issued under a different key shape.'
            );
        }

        $direction = CursorDirection::tryFrom($payload['d']);
        if ($direction === null) {
            throw new InvalidCursorException("Cursor has unknown direction '{$payload['d']}'.");
        }

        return [array_values($payload['v']), $direction];
    }
}
