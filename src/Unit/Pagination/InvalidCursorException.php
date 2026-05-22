<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

use RuntimeException;

/**
 * Thrown by {@see Paginator::cursor()} (via {@see CursorToken}) when a cursor
 * token cannot be safely consumed.
 *
 * Possible causes:
 * - Token is malformed (not base64, not JSON, missing required `s` / `v` / `d` fields).
 * - Token's embedded signature does not match the current {@see CursorKey} —
 *   typically means the cursor was issued under a different key shape
 *   (redeploy, API contract change) or has been tampered with.
 * - Token carries an unknown direction value (not `f` or `b`).
 * - Cursor value count does not match the key's column count.
 *
 * Callers should treat this as a client error (HTTP 400) — the cursor came
 * from the client and is no longer valid; refreshing the page (clearing
 * the cursor) is the typical recovery.
 */
final class InvalidCursorException extends RuntimeException
{
}
