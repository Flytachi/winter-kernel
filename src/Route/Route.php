<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route;

readonly class Route
{
    public string $regex;
    /** @var list<string> */
    public array $paramNames;

    public function __construct(
        public string $method,
        public string $path,
        public mixed  $handler,
    ) {
        [$this->regex, $this->paramNames] = self::compile($path);
    }

    public function isStatic(): bool
    {
        return !str_contains($this->path, '{');
    }

    /**
     * Converts "/users/{id:\d+}/posts/{slug}" into:
     *   regex      → "#^/users/(\d+)/posts/([^/]+)$#u"
     *   paramNames → ['id', 'slug']
     *
     * @return array{string, list<string>}
     */
    public static function compile(string $path): array
    {
        $paramNames = [];
        $parts      = preg_split('/(\{[^}]+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex      = '#^';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($part[0] === '{') {
                $inner = substr($part, 1, -1);
                if (str_contains($inner, ':')) {
                    [$name, $pattern] = explode(':', $inner, 2);
                } else {
                    $name    = $inner;
                    $pattern = '[^/]+';
                }
                $paramNames[] = $name;
                $regex .= '(' . $pattern . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        $regex .= '$#u';

        return [$regex, $paramNames];
    }
}
