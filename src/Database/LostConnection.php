<?php

declare(strict_types=1);

namespace Pulsar\Database;

use PDOException;
use Pulsar\Api\Internal;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function str_starts_with;

/**
 * Recognises the PDO errors that mean the server is no longer on the other end.
 *
 * A cached PDO handle outlives the TCP connection underneath it. Under FPM that
 * barely matters — the process ends with the request — but a persistent runtime keeps
 * the handle for the life of the worker, and a server restart, a `wait_timeout`, a
 * failover or a dropped packet leaves every later request talking to a socket that is
 * gone. The handle has to be recognised as dead and discarded; the alternative is one
 * network blip poisoning a worker until it is recycled.
 *
 * ## What counts
 *
 * - **SQLSTATE class `08`** — the SQL standard's *connection exception* class. This is
 *   the portable signal: `08006` connection_failure, `08003` connection_does_not_exist,
 *   `08001`, `08004`, `08007`.
 * - **PostgreSQL `57P01`, `57P02`, `57P03`** — admin shutdown, crash shutdown, cannot
 *   connect now. The server said goodbye; the handle is finished either way.
 * - **MySQL client/server codes `2006`, `2013`, `2055`, `4031`** — "server has gone
 *   away", "lost connection during query", "lost connection to server, system error",
 *   and 8.0's "client was disconnected by the server because of inactivity". These
 *   arrive under SQLSTATE `HY000`, which is a general error and cannot be matched on.
 *
 * Everything else is a query that failed, not a connection that died: a deadlock, a
 * constraint violation, a syntax error, a SQLite lock. Discarding the handle for those
 * would throw away a healthy connection on every application bug.
 */
#[Internal(reason: 'Connection-loss classification for PdoConnection')]
final class LostConnection
{
    /** PostgreSQL server-shutdown states, which are not in SQLSTATE class 08. */
    private const array SHUTDOWN_STATES = ['57P01', '57P02', '57P03'];

    /** MySQL codes for a connection that is no longer there. */
    private const array MYSQL_CODES = [2006, 2013, 2055, 4031];

    /**
     * Whether this exception says the connection itself is gone.
     */
    public static function occurred(PDOException $exception): bool
    {
        $sqlState = '';
        $driverCode = null;

        $info = $exception->errorInfo;

        if (is_array($info)) {
            /** @var mixed $state */
            $state = $info[0] ?? null;

            if (is_string($state)) {
                $sqlState = $state;
            }

            /** @var mixed $code */
            $code = $info[1] ?? null;

            if (is_int($code)) {
                $driverCode = $code;
            }
        }

        if ($sqlState === '') {
            // PDO puts the SQLSTATE in the exception code when it has no errorInfo to
            // offer — a failure to connect at all, most often.
            /** @var mixed $exceptionCode */
            $exceptionCode = $exception->getCode();

            if (is_string($exceptionCode)) {
                $sqlState = $exceptionCode;
            }
        }

        if (str_starts_with($sqlState, '08')) {
            return true;
        }

        if (in_array($sqlState, self::SHUTDOWN_STATES, true)) {
            return true;
        }

        return $driverCode !== null && in_array($driverCode, self::MYSQL_CODES, true);
    }
}
