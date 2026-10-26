<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

/**
 * Thrown when a component of the running application is to be started in the calling
 * process — {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::start()} — while
 * running it is the application's business: it runs there, or it is started there with
 * {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::dispatch()} or
 * `call process … start`. Also thrown for a component that runs in another environment
 * (a container), whose processes cannot be reached from here.
 *
 * A {@see ProcessAlreadyRunningException}, so code that guards a start against a
 * second instance keeps working unchanged.
 *
 * @link https://winterframe.net/docs/components Controlling a running component
 */
final class ProcessManagedException extends ProcessAlreadyRunningException
{
}
