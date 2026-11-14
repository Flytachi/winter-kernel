<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * Something the application runs beside its own code — a database, a cache — packaged
 * with what it needs: how it is started, checked and stopped ({@see component()}), and
 * what the container image must hold for it ({@see image()}).
 *
 * The kernel knows no service by name; a package does (winter-service-postgres, …), and
 * the application declares it like any other component:
 *
 * ```php
 * protected static function components(Components $components): void
 * {
 *     $components->service(new Postgres(version: 18, database: 'app'));
 * }
 * ```
 *
 * @link https://winterframe.net/docs/services Services inside the application
 */
interface Service
{
    /**
     * What the supervisor runs — an outside program with its readiness and alive checks,
     * its stop signal and its priority. Asked when the application builds its manifest.
     */
    public function component(): Component;

    /**
     * What the image needs for it: packages, directories, volumes, environment. Read by
     * `call service docker` only, never while the application runs.
     */
    public function image(): ServiceImage;
}
