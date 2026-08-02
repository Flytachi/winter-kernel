<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Pool;

use PDOException;
use Throwable;

/**
 * Tells a **lost connection** apart from an ordinary database error.
 *
 * The distinction is what makes eviction safe. A constraint violation (`23xxx`) or a
 * syntax error (`42xxx`) means the connection is perfectly healthy and the *query* was
 * wrong — throwing it away would churn the pool for nothing. A connection exception
 * means the socket is dead: keeping it would hand the corpse to the next borrower.
 *
 * Only the connection case is matched here, so
 * {@see PpaConnectionPool::reportFailure()} evicts exactly when it should.
 *
 * The classification reads the driver's own verdict — SQLSTATE and the driver error
 * code from `PDOException::$errorInfo` — walking the `previous` chain, because CDO
 * wraps the original `PDOException` inside a `CDOException`.
 */
final class ConnectionLoss
{
    /** SQLSTATE class 08 — "connection exception" in the SQL standard. */
    private const string CONNECTION_CLASS = '08';

    /** PostgreSQL: the server itself terminated the connection. */
    private const array SERVER_SHUTDOWN = ['57P01', '57P02', '57P03'];

    /** MySQL driver codes: server gone away / connection lost mid-query. */
    private const array MYSQL_LOST = [2006, 2013, 2055];

    /** SQLSTATE PDO reports when the driver gave it nothing to map. */
    private const string UNMAPPED = 'HY000';

    /**
     * Whether this failure means the connection itself died (as opposed to the query
     * being rejected by a healthy server).
     */
    public static function isLost(Throwable $error): bool
    {
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && self::matches($cause)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the driver's verdict is inconclusive, so only a live probe can tell a
     * dead connection from a rejected query.
     *
     * PDO_pgsql is the reason this exists. A killed PostgreSQL connection does **not**
     * arrive as SQLSTATE `08006`: with the socket gone there is no result object to
     * take a SQLSTATE from, so PDO falls back to `HY000` with driver code `7` — the
     * very same code it reports for an ordinary syntax error. Verified live: a
     * terminated backend yields `["HY000", 7, "terminating connection…"]`. Matching on
     * the message instead is not an option either, since PostgreSQL translates it
     * (`lc_messages`).
     *
     * A well-formed SQLSTATE from any other class means the server answered and is
     * alive, so those are decided here and never probed.
     */
    public static function isUndecided(Throwable $error): bool
    {
        if (self::isLost($error)) {
            return false;
        }

        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if (!$cause instanceof PDOException) {
                continue;
            }
            $info     = $cause->errorInfo;
            $sqlState = is_array($info) && isset($info[0]) ? (string) $info[0] : (string) $cause->getCode();

            return $sqlState === self::UNMAPPED || $sqlState === '' || $sqlState === '0';
        }

        return false;
    }

    private static function matches(PDOException $error): bool
    {
        $info = $error->errorInfo;
        // errorInfo is authoritative; fall back to the code, which carries the
        // SQLSTATE for exceptions raised outside a statement.
        $sqlState   = is_array($info) && isset($info[0]) ? (string) $info[0] : (string) $error->getCode();
        $driverCode = is_array($info) && isset($info[1]) ? $info[1] : null;

        return str_starts_with($sqlState, self::CONNECTION_CLASS)
            || in_array($sqlState, self::SERVER_SHUTDOWN, true)
            || (is_int($driverCode) && in_array($driverCode, self::MYSQL_LOST, true));
    }
}
