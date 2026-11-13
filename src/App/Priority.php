<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * When a component starts and stops, relative to the others: the higher its priority,
 * the longer it lives — it starts earlier and stops later.
 *
 * The application starts its components level by level: High, then Normal, then Low. A
 * level starts once every component of the one before it is ready — or settled where
 * waiting is pointless (failed and not critical, held by an outside process, finished).
 * Within a level they start together. A stop goes the other way: Low first, then Normal,
 * then High, each level once the one before it is down.
 *
 * Every component is Normal unless told otherwise, so an application that sets no
 * priority starts and stops everything at once, as it always did.
 *
 * {@see \Flytachi\Winter\Kernel\WinterApplication::onStarting()} runs once High is ready,
 * before Normal starts.
 *
 * @link https://winterframe.net/docs/components#priority Start and stop order
 */
enum Priority
{
    /** What the rest stands on — a database, Redis, a local nginx: up first, down last. */
    case High;
    /** The application's own work — processes, daemons, the scheduler. The default. */
    case Normal;
    /** What takes outside traffic — HTTP, WebSocket: up once all is ready, down first. */
    case Low;

    /** Start order: 0 starts first. */
    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Normal => 1,
            self::Low => 2,
        };
    }

    /** @return list<self> In start order. */
    public static function inStartOrder(): array
    {
        return [self::High, self::Normal, self::Low];
    }
}
