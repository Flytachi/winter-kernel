<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Schedule\Fixtures;

/**
 * Integration bean: its scheduled method appends a line to the file named by the
 * WK_MARKER env var, so a parent test can count how many times it fired.
 */
final class MarkerTask
{
    public function tick(): void
    {
        $path = getenv('WK_MARKER');
        if ($path !== false && $path !== '') {
            file_put_contents($path, "tick\n", FILE_APPEND | LOCK_EX);
        }
    }
}
