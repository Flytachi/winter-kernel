<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Pagination;

/**
 * Navigation direction encoded into a cursor token by {@see CursorToken}.
 *
 * The paginator emits two cursors per page — `cursorPrev` (backward) and
 * `cursorNext` (forward) — each tagged with its own direction. Clients
 * pass back whichever cursor they want to navigate by; the server reads
 * the direction from the token itself, so a single `$cursor` parameter is
 * sufficient.
 *
 * Single-character values keep the encoded token compact.
 */
enum CursorDirection: string
{
    case Forward  = 'f';   // after — fetch rows beyond the cursor (toward the end)
    case Backward = 'b';   // before — fetch rows ahead of the cursor (toward the start)
}
