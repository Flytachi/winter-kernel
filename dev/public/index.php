<?php

/*
    Autoload + Bootstrap
    --------------------
    Loads Composer dependencies and defines the Boot class (extends BaseBoot).
    All kernel configuration — paths, .env, DI, CORS, Health — lives in bootstrap.php.
    This file stays minimal: one require, one call.
*/
require '../bootstrap.php';

/*
    FPM entry point
    ---------------
    Boot::web() runs the full request lifecycle:

      1. configure()             — Kernel::init(), .env, logging
      2. DI scan                 — auto-discovers #[Singleton] / #[Request] / #[Transient]
      3. providers()             — service providers, manual bindings
      4. channels() / plugins()  — extra log channels, plugin routes
      5. httpCors() / health()   — CORS policy, /actuator endpoints
      6. LoggerFactory           — switches active channel to 'http'
      7. Router::resolve()       — loads route cache (production) or scans (dev/DEBUG=true)
      8. Router::static()        — serves files from public/ directly (dev server / Swoole)
      9. Router::handle()        — dispatches the request:
           a. Header::init()            snapshot superglobals → Header bag
           b. Locale::initFromRequest() detect Accept-Language / locale cookie
           c. Static file check         short-circuit for existing public files
           d. Global CORS headers       applied before dispatch (covers 404/500 too)
           e. OPTIONS preflight         returns 204 before handler invocation
           f. Route dispatch            O(1) static map → chunked regex dynamic scan
           g. Per-route #[CrossOrigin]  overrides global CORS if present
           h. Middleware before()       run in declaration order
           i. Controller method         resolved via ReflectionCache + ParameterResolver
           j. Middleware after()        run in reverse order
           k. Response serialise        Sendable::send() or ResponseEntity::ok()->send()
           l. Error handling            ExceptionWrapper maps Throwable → HTTP response

    Route cache:
      DEBUG=false — loads from storage/cache/mapping.php on warm boots;
                    scans and writes cache on first boot after deployment.
      DEBUG=true  — always rescans (dev mode, no stale routes).

    To inject per-request context fields into every log line:
      $ctx = LoggerFactory::contextStorage();
      $ctx->set('request_id', uniqid('', true));
      $ctx->set('user_id', $authenticatedUserId);
*/
Boot::web();
