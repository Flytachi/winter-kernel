<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route;

/**
 * Fast dispatcher — O(1) static lookup + grouped-regex dynamic matching.
 *
 * Routes sharing the same URI regex (e.g. GET /users/{id} and DELETE /users/{id})
 * are merged into one URI group so alternation branches never shadow each other.
 */
class Dispatcher
{
    private const int CHUNK_SIZE = 30;

    /** @var array<string, array<string, mixed>> [METHOD][path] => handler */
    private array $staticMap;

    /**
     * @var list<array{
     *   regex: string,
     *   groups: list<array{paramNames: list<string>, methods: array<string, mixed>}>,
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
        $this->dynamicChunks = self::buildChunks(self::groupByUri($dynamicRoutes));
    }

    public function dispatch(string $method, string $uri): RouteResult
    {
        $method = strtoupper($method);

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

            if (!isset($uriGroup['methods'][$method])) {
                $allowedMethods = array_merge($allowedMethods, array_keys($uriGroup['methods']));
                continue;
            }

            $offset = 1 + $groupIndex * $slots;
            $values = array_slice($matches, $offset, count($uriGroup['paramNames']));
            $params = $uriGroup['paramNames']
                ? array_combine($uriGroup['paramNames'], $values)
                : [];

            return new RouteResult(RouteResult::FOUND, $uriGroup['methods'][$method], $params);
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
