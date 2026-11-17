<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Front;

use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;

/**
 * A frontend's built files, sent by the HTTP server's worker itself.
 *
 * A file of the build is sent as it is — the operating system copies it to the socket
 * (`sendfile`), never through PHP's memory — with its type, a validator (`ETag`,
 * `Last-Modified`: a match answers 304) and its caching: content-hashed files
 * ({@see FrontSite::$assets}) for good, the rest revalidated on every visit. A
 * precompressed neighbour (`.br`, `.gz`) the client accepts is sent instead. A path that
 * names no file gets the site's shell when the caller allows it (a page of a single-page
 * app), a page of its own (`about/index.html`) on a pre-rendered site, and 404 otherwise —
 * a missing content-hashed file always 404 and never stored, or the shell would be cached
 * under a script's name for a year. Nothing outside the build is ever sent.
 *
 * @internal The router's, for a request no controller took.
 */
final class StaticFiles
{
    private const array TYPES = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'webmanifest' => 'application/manifest+json',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'wasm' => 'application/wasm',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'pdf' => 'application/pdf',
    ];

    private const string IMMUTABLE = 'public, max-age=31536000, immutable';

    /** A pre-rendered site's page for what it has not: `nuxt generate` writes it. */
    private const string NOT_FOUND_PAGE = '/404.html';

    /** Files of one build the index holds: past them, the rest is not served. */
    private const int MAX_FILES = 100_000;

    /** Seconds since a build's newest file after which it counts as written. */
    private const int SETTLED = 5;

    /**
     * @var array<string, array{stamp: string, files: array<string, string>, walked: int, fresh: bool}>
     *     By site root, per worker.
     */
    private static array $index = [];

    private function __construct()
    {
    }

    /**
     * @param bool $shellAllowed Whether an unknown path may get the shell: the request is
     *     a page the browser asked for, not a script or an API call.
     * @return bool Whether it answered — false: nothing of the frontend's is there (no file,
     *     no shell for this request), the router's 404 or 405 is the answer.
     */
    public static function serve(
        FrontSite $site,
        HttpRequest $request,
        HttpResponse $response,
        bool $shellAllowed,
    ): bool {
        $relative = self::relative($site, $request->getPath());
        if ($relative === null) {
            return false;
        }
        $hashed = $site->assets !== null && str_starts_with($relative, $site->assets . '/');
        $files = self::files($site);
        if ($files === null) {
            if (!$shellAllowed && !$hashed) {
                return false;
            }
            $response->status(503);
            $response->header('Content-Type', 'text/plain; charset=utf-8');
            $response->header('Retry-After', '5');
            $response->header('Cache-Control', 'no-store');
            $response->end('the frontend is not built');
            return true;
        }

        foreach (self::candidates($relative) as $candidate) {
            if (isset($files[$candidate])) {
                self::send($request, $response, $files, $candidate, $hashed ? self::IMMUTABLE : 'no-cache');
                return true;
            }
        }
        if ($hashed) {
            self::notFound($response, 'no-store');
            return true;
        }
        if ($site->shell !== null && $shellAllowed && isset($files['/' . $site->shell])) {
            self::send($request, $response, $files, '/' . $site->shell, 'no-cache');
            return true;
        }
        // A pre-rendered site's page that was not built: its own 404 page, when the build has
        // one and a page is asked for — said with 404, never revalidated into a 304.
        if ($site->shell === null && $shellAllowed && isset($files[self::NOT_FOUND_PAGE])) {
            self::send($request, $response, $files, self::NOT_FOUND_PAGE, 'no-cache', 404);
            return true;
        }
        return false;
    }

    /**
     * The build's files, by their path within it — their real paths, only those inside it
     * (a link out of it is not there). Built once per worker and again whenever the
     * marker changes: a build rewrites it. Null when there is no build.
     *
     * The index is what keeps a request to one look at the marker and one at the file
     * sent: under the coroutine hooks every look at the disk is a trip to a thread.
     *
     * @return array<string, string>|null
     */
    private static function files(FrontSite $site): ?array
    {
        // Under the coroutine hooks a second stat of a path is answered from a cache that a
        // build in another process does not clear: the old marker would hide a rebuild, the
        // old ETag would answer 304 for new files. Cleared for the path, it costs no call.
        clearstatcache(true, $site->marker());
        $marker = @stat($site->marker());
        if ($marker === false) {
            unset(self::$index[$site->root]);
            return null;
        }
        $stamp = $marker['mtime'] . ':' . $marker['size'] . ':' . $marker['ino'];
        $known = self::$index[$site->root] ?? null;
        $now = time();
        if ($known !== null && $known['stamp'] === $stamp) {
            // A build being written now — files newer than a few seconds when it was
            // walked — is walked again, once a second, until it settles: build tools do not
            // write the marker last.
            if (!$known['fresh'] || $now - $known['walked'] < 1) {
                return $known['files'];
            }
        }
        // Through a link swapped by a deploy (current -> releases/N) the cached real path
        // would still be the old release's.
        clearstatcache(true);
        $root = realpath($site->root);
        if ($root === false) {
            return null;
        }
        $files = [];
        $fresh = false;
        try {
            // A folder that cannot be read is left out, not the whole build.
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($walk as $file) {
                /** @var \SplFileInfo $file */
                $real = realpath($file->getPathname());
                if ($real === false || !is_file($real) || !str_starts_with($real, $root . '/')) {
                    continue;
                }
                $files[substr($file->getPathname(), strlen($root))] = $real;
                $fresh = $fresh || $file->getMTime() > $now - self::SETTLED;
                if (count($files) >= self::MAX_FILES) {
                    break;
                }
            }
        } catch (\UnexpectedValueException) {
            // The root itself went while it was walked: what was found is what there is.
        }
        self::$index[$site->root] = ['stamp' => $stamp, 'files' => $files, 'walked' => $now, 'fresh' => $fresh];
        return $files;
    }

    /** The path within the site — `/` or `/a/b` — decoded; null for one it never serves. */
    private static function relative(FrontSite $site, string $path): ?string
    {
        if ($site->path !== '/') {
            $path = substr($path, strlen($site->path));
        }
        $path = rawurldecode($path);
        if ($path === '' || $path === false) {
            return '/';
        }
        if (str_contains($path, "\0") || str_contains($path, '\\') || !str_starts_with($path, '/')) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return null;
            }
        }
        return $path;
    }

    /** @return list<string> */
    private static function candidates(string $relative): array
    {
        if (str_ends_with($relative, '/')) {
            return [$relative . 'index.html'];
        }
        return [$relative, $relative . '/index.html', $relative . '.html'];
    }

    /**
     * @param array<string, string> $files The build's index.
     * @param string $key The file's path within the build — its precompressed neighbours
     *     are `$key.br` and `$key.gz` there.
     */
    private static function send(
        HttpRequest $request,
        HttpResponse $response,
        array $files,
        string $key,
        string $cache,
        int $status = 200,
    ): void {
        $file = $files[$key];
        clearstatcache(true, $file);
        $stat = @stat($file);
        if ($stat === false) {
            self::notFound($response, 'no-cache');
            return;
        }
        $etag = sprintf('"%x-%x"', $stat['size'], $stat['mtime']);
        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        $response->header('Content-Type', $type);
        $response->header('Cache-Control', $cache);
        $response->header('ETag', $etag);
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT');
        $response->header('X-Content-Type-Options', 'nosniff');
        if ($status !== 200) {
            $response->status($status);
        } elseif ($request->getHeader('If-None-Match') === $etag) {
            $response->status(304);
            $response->end('');
            return;
        }

        $sent = $file;
        $accepted = strtolower((string) $request->getHeader('Accept-Encoding'));
        foreach (['br' => '.br', 'gzip' => '.gz'] as $encoding => $suffix) {
            if ($accepted !== '' && str_contains($accepted, $encoding) && isset($files[$key . $suffix])) {
                $sent = $files[$key . $suffix];
                $response->header('Content-Encoding', $encoding);
                $response->header('Vary', 'Accept-Encoding');
                break;
            }
        }
        if (strtoupper($request->getMethod()) === 'HEAD') {
            // The adapter sends no body for HEAD: the length GET would send is said here. A
            // client that takes an encoding makes Swoole drop a length it was not told the
            // encoding of — `identity` keeps it, as the adapter's end() does.
            if ($sent === $file) {
                $response->header('Content-Encoding', 'identity');
            }
            clearstatcache(true, $sent);
            $response->header('Content-Length', (string) filesize($sent));
        }
        $response->sendfile($sent);
    }

    private static function notFound(HttpResponse $response, string $cache): void
    {
        $response->status(404);
        $response->header('Content-Type', 'text/plain; charset=utf-8');
        $response->header('Cache-Control', $cache);
        $response->end('Not Found');
    }
}
