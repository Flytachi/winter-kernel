<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * Thrown when {@see \Flytachi\Winter\Kernel\Application::components()} is malformed —
 * a non-{@see Component} entry, a missing Http host for a bundle, or a component
 * kind the current runtime cannot host.
 */
final class ApplicationConfigException extends \RuntimeException
{
}
