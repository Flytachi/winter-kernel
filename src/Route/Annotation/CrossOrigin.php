<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route\Annotation;

use Attribute;

/**
 * Per-route CORS policy — mirrors Spring's @CrossOrigin.
 *
 * When placed on a controller class or method it overrides (not merges)
 * the global CORS config set via Router::cors().
 *
 * Usage:
 *   // Entire controller
 *   #[CrossOrigin(origins: ['https://app.example.com'], credentials: true)]
 *   class UserController extends Controller { ... }
 *
 *   // Single endpoint
 *   #[CrossOrigin(origins: ['https://admin.example.com'], maxAge: 3600)]
 *   #[GetMapping('stats')]
 *   public function stats(): ResponseEntity { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class CrossOrigin
{
    /**
     * @param string[] $origins        Allowed origins. Empty = '*'.
     * @param string[] $allowHeaders   Allowed request headers. Empty = reflect Access-Control-Request-Headers.
     * @param string[] $exposeHeaders  Response headers exposed to JavaScript.
     * @param bool     $credentials    Allow cookies / Authorization. Requires explicit origins (not '*').
     * @param int      $maxAge         Preflight cache TTL in seconds (Access-Control-Max-Age).
     * @param string[] $vary           Extra values for the Vary header.
     */
    public function __construct(
        public array $origins       = [],
        public array $allowHeaders  = [],
        public array $exposeHeaders = [],
        public bool  $credentials   = false,
        public int   $maxAge        = 0,
        public array $vary          = [],
    ) {}
}
