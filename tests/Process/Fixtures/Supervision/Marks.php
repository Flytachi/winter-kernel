<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures\Supervision;

/** Appends `<who> <event>` to the file named by WK_MARKER, so a test can count runs. */
final class Marks
{
    public static function add(string $who, string $event): void
    {
        $path = getenv('WK_MARKER');
        if ($path !== false && $path !== '') {
            file_put_contents($path, "{$who} {$event}\n", FILE_APPEND | LOCK_EX);
        }
    }
}
