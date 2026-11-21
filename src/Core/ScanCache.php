<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core;

/**
 * How this process treats the project's class-list cache (`.winter/build/di.php`).
 *
 * The cache is a list of class names: a hit skips walking the project's folders and
 * tokenising every file, nothing more — the classes are loaded and reflected either way.
 * It is never checked for being out of date (a check would cost the walk it saves), so
 * who writes it decides whether it can be: only a start of the application does, and
 * `call di build`.
 *
 * `DEBUG=true` overrides every mode: the project is walked and the file left as it is.
 *
 * @see ClassScanner::cachePath()
 */
enum ScanCache
{
    /**
     * Read the list when it is there, never create it — a console command. A command run
     * before a class was added must not leave a list that hides it from the next start.
     */
    case Read;

    /**
     * Walk the project once and write the list anew; whatever scans after, in this process
     * and its workers, reads that list — `call run`, `call application start`.
     */
    case Refresh;

    /**
     * Walk the project on every scan, and drop the list a start left behind: under
     * `call run dev` it would be out of date with the first new class, for this process
     * and for the commands run beside it.
     */
    case Live;
}
