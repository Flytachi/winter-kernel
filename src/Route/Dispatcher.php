<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route;

/**
 * Fast dispatcher — O(1) static lookup + grouped-regex dynamic matching.
 *
 * Routes sharing the same URI regex (e.g. GET /users/{id} and DELETE /users/{id})
 * are merged into one URI group so alternation branches never shadow each other.
 *
 * Groups are ordered from the most specific pattern to the least before they are
 * chunked, because a chunk regex is an alternation and PCRE keeps its leftmost
 * matching branch: without that order the winner of a URI two patterns accept would
 * be whichever route happened to be registered first.
 */
final class Dispatcher
{
    private const int CHUNK_SIZE = 30;

    /** Stand-ins for a placeholder while a path is being ranked. */
    private const string FREE_MARK        = "\x00";
    private const string CONSTRAINED_MARK = "\x01";
    /** Closes the rank digits of a sort key; ranks are 0-3, so this outranks them all. */
    private const string KEY_END          = '9';

    /** @var array<string, array<string, mixed>> [METHOD][path] => handler */
    private array $staticMap;

    /**
     * @var list<array{
     *   regex: string,
     *   groups: list<array{regex: string, paramNames: list<string>, methods: array<string, mixed>}>,
     *   slotsPerGroup: int
     * }>
     */
    private array $dynamicChunks;

    /**
     * @param array<string, array<string, mixed>> $staticRoutes
     * @param list<Route>                         $dynamicRoutes
     */
    public function __construct(array $staticRoutes, array $dynamicRoutes)
    {
        $this->staticMap     = $staticRoutes;
        $this->dynamicChunks = self::buildChunks(self::groupByUri(self::sortBySpecificity($dynamicRoutes)));
    }

    /**
     * Resolve a request to a handler.
     *
     * `HEAD` falls back to the `GET` route when it matched nothing of its own, because
     * RFC 9110 §9.3.2 defines it as `GET` without a body: a resource answering `GET`
     * has to answer `HEAD` too, and the response adapters already drop the body while
     * keeping the headers. The fallback runs only after an explicit `HEAD` route has
     * missed, so registering one still wins; and only on a miss, so no other method is
     * touched. A path that exists under neither still reports the same
     * `METHOD_NOT_ALLOWED`/`NOT_FOUND` it did before.
     */
    public function dispatch(string $method, string $uri): RouteResult
    {
        $method = strtoupper($method);
        $result = $this->match($method, $uri);

        if ($method === 'HEAD' && $result->status !== RouteResult::FOUND) {
            $viaGet = $this->match('GET', $uri);
            if ($viaGet->status === RouteResult::FOUND) {
                return $viaGet;
            }
        }

        return $result;
    }

    /** Exact method + URI resolution, with no fallback of any kind. */
    private function match(string $method, string $uri): RouteResult
    {
        // ── 1. Static lookup ─────────────────────────────────────────────────
        if (isset($this->staticMap[$method][$uri])) {
            return new RouteResult(RouteResult::FOUND, $this->staticMap[$method][$uri]);
        }

        // ── 2. Dynamic matching ──────────────────────────────────────────────
        $allowedMethods = [];

        foreach ($this->dynamicChunks as $chunk) {
            if (!preg_match($chunk['regex'], $uri, $matches)) {
                continue;
            }

            $slots      = $chunk['slotsPerGroup'];
            $groupIndex = $this->matchedGroupIndex($matches, $slots);
            $uriGroup   = $chunk['groups'][$groupIndex];

            if (isset($uriGroup['methods'][$method])) {
                return self::found($uriGroup, $method, $matches, 1 + $groupIndex * $slots);
            }

            // The combined regex reports its leftmost matching branch only, so the
            // groups behind the winner are still candidates for this URI. They are
            // reached one regex at a time — a cost paid only on this path, where the
            // answer used to be a wrong 405.
            $allowedMethods = array_merge($allowedMethods, array_keys($uriGroup['methods']));

            foreach (array_slice($chunk['groups'], $groupIndex + 1) as $group) {
                if (!preg_match($group['regex'], $uri, $groupMatches)) {
                    continue;
                }
                if (isset($group['methods'][$method])) {
                    return self::found($group, $method, $groupMatches, 1);
                }
                $allowedMethods = array_merge($allowedMethods, array_keys($group['methods']));
            }
        }

        // ── 3. Method-not-allowed check on statics ───────────────────────────
        foreach ($this->staticMap as $m => $paths) {
            if ($m !== $method && isset($paths[$uri])) {
                $allowedMethods[] = $m;
            }
        }

        if ($allowedMethods !== []) {
            return new RouteResult(
                RouteResult::METHOD_NOT_ALLOWED,
                allowedMethods: array_values(array_unique($allowedMethods))
            );
        }

        return new RouteResult(RouteResult::NOT_FOUND);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Bind the capture values starting at $offset to the group's parameter names.
     *
     * @param array{regex: string, paramNames: list<string>, methods: array<string, mixed>} $group
     * @param list<string>                                                                  $matches
     */
    private static function found(array $group, string $method, array $matches, int $offset): RouteResult
    {
        $values = array_slice($matches, $offset, count($group['paramNames']));
        $params = $group['paramNames']
            ? array_combine($group['paramNames'], $values)
            : [];

        return new RouteResult(RouteResult::FOUND, $group['methods'][$method], $params);
    }

    /**
     * Order routes from the most specific pattern to the least.
     *
     * Every path is reduced to a sortable key so the ordering is one `ksort()` over
     * strings instead of a comparison callback per pair — the whole pass runs once per
     * dispatcher, which under FPM means once per request.
     *
     * The key is `catchAll . segmentRanks . KEY_END . registrationIndex`, each rank
     * stored inverted (`3 - rank`) so plain ascending order puts the most specific
     * path first. {@see self::KEY_END} outranks every rank digit, so where one path's
     * ranks are a prefix of another's the longer path still wins; and the trailing
     * index, reached only by paths that rank identically, keeps them in the order they
     * were registered in.
     *
     * @param  list<Route> $routes
     * @return list<Route>
     */
    private static function sortBySpecificity(array $routes): array
    {
        $keyed = [];
        foreach ($routes as $index => $route) {
            $keyed[self::rankKey($route->path) . sprintf('%08d', $index)] = $route;
        }

        ksort($keyed, SORT_STRING);

        return array_values($keyed);
    }

    /**
     * Reduce a path to its sort key for {@see self::sortBySpecificity()}.
     *
     * Specificity is read segment by segment, left to right: a literal outranks a
     * segment mixing literal text with a placeholder, which outranks a constrained
     * placeholder, which outranks a free one. A pattern able to swallow `/` is a
     * catch-all and sorts behind everything else whatever its segments say.
     */
    private static function rankKey(string $path): string
    {
        $parts    = preg_split('/(\{[^}]+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        $catchAll = false;
        $marked   = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($part[0] !== '{') {
                $marked .= $part;
                continue;
            }

            $inner = substr($part, 1, -1);

            // An unconstrained placeholder compiles to [^/]+ and never spans segments.
            if (!str_contains($inner, ':')) {
                $marked .= self::FREE_MARK;
                continue;
            }

            $catchAll = $catchAll || self::spansSegments(explode(':', $inner, 2)[1]);
            $marked  .= self::CONSTRAINED_MARK;
        }

        $key = $catchAll ? '1' : '0';
        foreach (explode('/', $marked) as $segment) {
            $key .= 3 - self::segmentRank($segment);
        }

        return $key . self::KEY_END;
    }

    /** Literal 3 > mixed 2 > constrained placeholder 1 > free placeholder 0. */
    private static function segmentRank(string $segment): int
    {
        $literal = str_replace([self::FREE_MARK, self::CONSTRAINED_MARK], '', $segment);

        if ($literal === $segment) {
            return 3;
        }
        if ($literal !== '') {
            return 2;
        }

        return str_contains($segment, self::CONSTRAINED_MARK) ? 1 : 0;
    }

    /** Whether a placeholder pattern can span more than one URI segment. */
    private static function spansSegments(string $pattern): bool
    {
        return @preg_match('#^(?:' . $pattern . ')$#u', 'a/b') === 1;
    }

    /** @return list<array{regex: string, paramNames: list<string>, methods: array<string, mixed>}> */
    private static function groupByUri(array $routes): array
    {
        $map    = [];
        $groups = [];

        foreach ($routes as $route) {
            $key = $route->regex;
            if (!isset($map[$key])) {
                $map[$key] = count($groups);
                $groups[]  = [
                    'regex'      => $key,
                    'paramNames' => $route->paramNames,
                    'methods'    => [],
                ];
            }
            $groups[$map[$key]]['methods'][$route->method] = $route->handler;
        }

        return $groups;
    }

    private static function buildChunks(array $uriGroups): array
    {
        if ($uriGroups === []) {
            return [];
        }

        $result = [];
        foreach (array_chunk($uriGroups, self::CHUNK_SIZE) as $chunk) {
            $maxParams     = max(array_map(static fn($g) => count($g['paramNames']), $chunk));
            $combinedParts = [];

            foreach ($chunk as $group) {
                $inner    = preg_replace('#^\#\^(.+)\$\#u$#', '$1', $group['regex']);
                $padding  = $maxParams - count($group['paramNames']);
                $inner   .= str_repeat('()', $padding);
                $combinedParts[] = $inner;
            }

            $result[] = [
                'regex'         => '#^(?:' . implode('|', $combinedParts) . ')$#u',
                'groups'        => $chunk,
                'slotsPerGroup' => $maxParams,
            ];
        }

        return $result;
    }

    /**
     * Determine which URI group matched.
     *
     * PHP 8 PCRE2: groups before winner → "" (present), groups after winner → absent.
     * count($matches) = 1 + (winnerIndex + 1) * slotsPerGroup
     * → winnerIndex = (count($matches) - 1) / slotsPerGroup - 1
     */
    private function matchedGroupIndex(array $matches, int $slotsPerGroup): int
    {
        if ($slotsPerGroup === 0) {
            return 0;
        }
        return (int) ((count($matches) - 1) / $slotsPerGroup) - 1;
    }
}
