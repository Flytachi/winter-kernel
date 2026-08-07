<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

final class Cors
{
    private static ?array $config = null;

    private function __construct()
    {
    }

    /**
     * Configure global CORS. Called once in bootstrap.php.
     *
     * @param string[] $origins        Allowed origins. Empty = '*'.
     * @param string[] $allowHeaders   Allowed request headers.
     * @param string[] $exposeHeaders  Response headers exposed to JS.
     * @param bool     $credentials    Allow cookies/Authorization (requires explicit origins).
     * @param int      $maxAge         Preflight cache TTL in seconds.
     * @param string[] $vary           Extra Vary header values.
     */
    public static function configure(
        array $origins = [],
        array $allowHeaders = [],
        array $exposeHeaders = [],
        bool $credentials = false,
        int $maxAge = 0,
        array $vary = [],
    ): void {
        self::$config = compact('origins', 'allowHeaders', 'exposeHeaders', 'credentials', 'maxAge', 'vary');
    }

    public static function getConfig(): ?array
    {
        return self::$config;
    }
}
