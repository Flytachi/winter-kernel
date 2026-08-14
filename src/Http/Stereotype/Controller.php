<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Stereotype;

/**
 * Base class for HTTP controllers. Routes are declared with mapping attributes on the
 * methods; the container builds the instance per request.
 *
 * The constructor is `final` and takes no arguments on purpose — dependencies come in
 * through `#[Autowired]` properties, so a route method never has to thread them.
 *
 * @link https://winterframe.net/docs/controllers Controllers, dependencies and returning responses
 */
abstract class Controller implements ControllerInterface
{
    final public function __construct()
    {
    }
}
