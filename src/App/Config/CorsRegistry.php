<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Config;

use Flytachi\Winter\K2\Http\Cors;

/**
 * Fluent builder handed to {@see WebConfigurer::configureCors()}. Collects the
 * global CORS policy and applies it through {@see Cors::configure()}.
 *
 * ```
 * $cors->allowedOrigins('https://app.example.com')
 *      ->allowedHeaders('Content-Type', 'Authorization')
 *      ->exposeHeaders('X-Request-Id')
 *      ->allowCredentials(true)
 *      ->maxAge(3600);
 * ```
 */
final class CorsRegistry
{
    /** @var list<string> */
    private array $origins = [];
    /** @var list<string> */
    private array $allowHeaders = [];
    /** @var list<string> */
    private array $exposeHeaders = [];
    /** @var list<string> */
    private array $vary = [];
    private bool $credentials = false;
    private int $maxAge = 0;
    private bool $touched = false;

    public function allowedOrigins(string ...$origins): self
    {
        $this->origins = array_merge($this->origins, array_values($origins));
        return $this->touch();
    }

    public function allowedHeaders(string ...$headers): self
    {
        $this->allowHeaders = array_merge($this->allowHeaders, array_values($headers));
        return $this->touch();
    }

    public function exposeHeaders(string ...$headers): self
    {
        $this->exposeHeaders = array_merge($this->exposeHeaders, array_values($headers));
        return $this->touch();
    }

    public function vary(string ...$headers): self
    {
        $this->vary = array_merge($this->vary, array_values($headers));
        return $this->touch();
    }

    public function allowCredentials(bool $enabled = true): self
    {
        $this->credentials = $enabled;
        return $this->touch();
    }

    public function maxAge(int $seconds): self
    {
        $this->maxAge = $seconds;
        return $this->touch();
    }

    /** True once any method has been called (so an empty configurer is a no-op). */
    public function isTouched(): bool
    {
        return $this->touched;
    }

    /**
     * Applies the collected policy to the global {@see Cors} config.
     */
    public function apply(): void
    {
        Cors::configure(
            origins: $this->origins,
            allowHeaders: $this->allowHeaders,
            exposeHeaders: $this->exposeHeaders,
            credentials: $this->credentials,
            maxAge: $this->maxAge,
            vary: $this->vary,
        );
    }

    private function touch(): self
    {
        $this->touched = true;
        return $this;
    }
}
