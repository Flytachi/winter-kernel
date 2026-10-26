<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Supervision;

use Flytachi\Winter\Kernel\App\Component;

/**
 * What the supervisor writes and reads about its components outside their own
 * processes: the record of a component that is down, and whether a process outside the
 * application holds one.
 *
 * @internal Implemented by the application over the process records.
 */
interface ComponentRecords
{
    /** Records a component the application could not keep running. */
    public function failed(Component $component): void;

    /** Records a component that is down on request, or whose work is done. */
    public function stopped(Component $component): void;

    /** Removes what the supervisor recorded: the component is about to start, or the application ends. */
    public function forget(Component $component): void;

    /** The PID of a live instance of the component the application did not start, or null. */
    public function outsider(Component $component): ?int;
}
