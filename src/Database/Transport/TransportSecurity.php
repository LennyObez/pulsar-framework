<?php

declare(strict_types=1);

namespace Pulsar\Database\Transport;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Throwable;

use function is_string;
use function sprintf;

/**
 * What each engine can be asked about the transport its connections travel over.
 *
 * Three questions live here rather than at the call site, for the reason
 * {@see \Pulsar\Database\Dialect\DialectInterface} sets out at length: code that writes
 * `match ($driver)` has taken on the job of knowing every engine the framework will ever
 * support, and an exhaustive match throws `UnhandledMatchError` the first time it meets a
 * case that did not exist when it was written. Inside `src/Database` that dispatch is the
 * design — the analysers enforce exhaustiveness here, so adding an engine fails this
 * build rather than somebody else's deployment.
 *
 * ## Why this is not on the dialect
 *
 * A dialect describes how an engine wants its SQL written, and nothing here is about the
 * shape of a statement. Two of the three answers are needed before anything has
 * connected — they are read from a `ConnectionConfig`, which carries a {@see Driver} and
 * no session — so putting them behind an object that only exists once a connection does
 * would make the question unaskable at the point it is asked.
 *
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class TransportSecurity
{
    /**
     * Whether this engine reaches its data over a network at all.
     *
     * False for SQLite, which is a local file. A TLS requirement applied to it is not
     * failed but vacuous: there is no transport to encrypt, so an assessor that grades
     * transport security has to skip such a connection rather than report it plaintext.
     */
    #[NoDiscard]
    public static function crossesNetwork(Driver $driver): bool
    {
        return match ($driver) {
            Driver::MySQL, Driver::PostgreSQL => true,
            Driver::SQLite => false,
        };
    }

    /**
     * Whether an `sslmode` entry in a connection's option array ever reaches the server.
     *
     * Only PostgreSQL's DSN carries it, which is why {@see Driver::buildDsn()} refuses
     * the parameter outright for every other engine. PDO indexes driver options by
     * integer constant and drops string keys, so on MySQL such an entry never leaves
     * PHP — counting it there is what once let a plaintext MySQL connection be reported
     * as encrypted.
     */
    #[NoDiscard]
    public static function honoursSslModeOption(Driver $driver): bool
    {
        return match ($driver) {
            Driver::PostgreSQL => true,
            Driver::MySQL, Driver::SQLite => false,
        };
    }

    /**
     * Ask the server what the live session actually negotiated.
     *
     * Null is returned when the engine keeps no such answer, or when the query fails.
     * It means "the server did not say", never "the session is plaintext": a failed
     * catalogue read turned into a negative finding reports a gap the deployment may not
     * have, and a report that cries wolf is one operators learn to override. A caller
     * that needs a verdict has to fall back to inspecting configuration, and grade that
     * fallback lower — configuration records what was asked for, not what happened.
     *
     * SQLite is null for a stronger reason than "it keeps no such answer": it has no
     * session to answer for. This used to return `encrypted: true` with the detail
     * "SQLite is a local file; the session has no network transport to encrypt" — a
     * sentence that says there is no transport and a flag that says the transport is
     * encrypted. A compliance assessor read the flag, and a deployment that encrypts
     * nothing carried PCI Req 2.3 at the strongest grade available. A boolean cannot
     * express "there is nothing to be encrypted", so this method declines to try;
     * {@see crossesNetwork()} is the question to ask first, and the answer to what an
     * absent transport means belongs to the caller that knows what it is assessing.
     */
    #[NoDiscard]
    public static function negotiated(ConnectionInterface $connection): ?NegotiatedTransport
    {
        try {
            return match ($connection->driver()) {
                Driver::PostgreSQL => self::negotiatedPostgres($connection),
                Driver::MySQL => self::negotiatedMysql($connection),
                Driver::SQLite => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `pg_stat_ssl` holds one row per backend; the session's own is selected by
     * `pg_backend_pid()`. The `ssl` column is a boolean, which PDO surfaces as `t`/`f`,
     * `1`/`0` or a real bool depending on how the driver was built — all four are
     * accepted rather than assuming one.
     */
    private static function negotiatedPostgres(ConnectionInterface $connection): ?NegotiatedTransport
    {
        $row = $connection
            ->query('SELECT ssl, version, cipher FROM pg_stat_ssl WHERE pid = pg_backend_pid()')
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var mixed $ssl */
        $ssl = $row->getOrDefault('ssl');

        if (!($ssl === true || $ssl === 't' || $ssl === 1 || $ssl === '1')) {
            return new NegotiatedTransport(
                encrypted: false,
                detail: 'pg_stat_ssl reports the current session is NOT using TLS.',
            );
        }

        /** @var mixed $version */
        $version = $row->getOrDefault('version');
        /** @var mixed $cipher */
        $cipher = $row->getOrDefault('cipher');

        return new NegotiatedTransport(
            encrypted: true,
            detail: sprintf(
                'pg_stat_ssl confirms the session is encrypted (%s, %s).',
                is_string($version) && $version !== '' ? $version : 'unreported protocol',
                is_string($cipher) && $cipher !== '' ? $cipher : 'unreported cipher',
            ),
        );
    }

    /**
     * `Ssl_cipher` is a session status variable, empty exactly when the session
     * negotiated no TLS. `SHOW STATUS` is used rather than a catalogue table because the
     * catalogue moved: MySQL 8.0 dropped `information_schema.SESSION_STATUS` in favour
     * of `performance_schema.session_status`, which a hardened deployment may have
     * compiled out, while MariaDB kept the original. The statement asked here is the one
     * spelling every server in the family answers.
     */
    private static function negotiatedMysql(ConnectionInterface $connection): ?NegotiatedTransport
    {
        $row = $connection->query('SHOW STATUS LIKE ' . "'Ssl_cipher'")->first();

        if ($row === null) {
            return null;
        }

        /** @var mixed $cipher */
        $cipher = $row->getOrDefault('Value');

        if (!is_string($cipher) || $cipher === '') {
            return new NegotiatedTransport(
                encrypted: false,
                detail: 'MySQL reports an empty Ssl_cipher: the session negotiated no TLS.',
            );
        }

        return new NegotiatedTransport(
            encrypted: true,
            detail: sprintf('MySQL reports the session cipher %s.', $cipher),
        );
    }
}
