<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ExecutedSubject;
use Pulsar\Compliance\Control\Measurement;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\SubjectAbsence;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Transport\TransportSecurity;

use function constant;
use function count;
use function defined;
use function implode;
use function in_array;
use function is_int;
use function is_string;
use function sprintf;
use function str_starts_with;
use function strtolower;

/**
 * Whether the database transport is encrypted, and how we know.
 *
 * Three mechanisms, deliberately graded apart:
 *
 *  - {@see TransportSecurity::negotiated()} asks the server what it actually
 *    negotiated. That is behaviour, so it grades {@see ObservationGrade::Measured},
 *    and it is what gives PCI Req 2.3 an honest route to Satisfied.
 *  - {@see connectionsWithoutTls()} inspects the connection options. That is a
 *    config read, so it grades {@see ObservationGrade::Declared} and can never on
 *    its own carry a control — an operator can write `sslmode=require` against a
 *    server that ignores it.
 *  - {@see networkedConnections()} answers whether there is a transport here at
 *    all. An empty answer is neither of the two above: it is
 *    {@see Observation::noSubject()}, which proves nothing and accuses nothing.
 *    This is the distinction the class was missing, and it was missing it in the
 *    dangerous direction — see {@see observe()}.
 *
 * This class grades; it does not know engines. Which catalogue answers "is this
 * session encrypted", whether an engine has a network transport at all, and whether
 * an `sslmode` option ever reaches the server are all engine facts, and they live in
 * {@see TransportSecurity} where the analysers make adding an engine a compile
 * failure instead of a silently unhandled case.
 *
 * The option inspection is the logic that used to be locked inside private static
 * methods of `ComplianceVerificationWiring`; that wiring now delegates here, so the
 * boot-time verifier and the evidence gatherer cannot drift into disagreeing about
 * the same connection.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class DatabaseTlsObserver
{
    /**
     * `sslmode` values that actually require TLS. Weaker values ("disable",
     * "allow", "prefer") let the connection silently fall back to plaintext.
     *
     * @var list<string>
     */
    private const array TLS_SSL_MODES = ['require', 'required', 'verify-ca', 'verify-full', 'verify_ca', 'verify_full'];

    /** What the negotiated-session measurement names as having run, in the report. */
    private const string SESSION_SUBJECT = 'the negotiated database session';

    /**
     * Observe the transport security of the deployment's database connections.
     *
     * Four answers, and the first two are the ones this method got wrong before.
     * A deployment with no database, and a deployment whose only engine is a local
     * file, both used to be reported `present: true` on the reasoning that nothing
     * unencrypted was travelling — which is true, and is not the same claim as "the
     * transport is encrypted". PCI Req 2.3 reached Satisfied on the sentence
     * "SQLite is a local file; the session has no network transport to encrypt",
     * at the strongest grade in the vocabulary. Both now answer
     * {@see Observation::noSubject()}: not a pass, not a gap, and no longer able
     * to carry anything.
     *
     * @param ConnectionInterface|null $connection The live connection, when one is
     *        wired. Its absence downgrades the observation to a config read rather
     *        than failing it: the config still names what would be dialled.
     */
    #[NoDiscard]
    public function observe(?DatabaseConfig $config, ?ConnectionInterface $connection): Observation
    {
        if ($config === null || $config->connections === []) {
            return Observation::noSubject(
                ObservationId::DatabaseTransportEncrypted,
                SubjectAbsence::noneIn(
                    'configured database connections',
                    [],
                    'No database connection is configured, so this deployment has no database '
                        . 'transport. There is nothing here to encrypt and nothing here that was '
                        . 'observed encrypted.',
                ),
                self::class,
            );
        }

        $connections = $config->connections;
        $networked = $this->networkedConnections($config);

        if ($networked === []) {
            return Observation::noSubject(
                ObservationId::DatabaseTransportEncrypted,
                SubjectAbsence::noneIn(
                    'database connections that cross a network',
                    $networked,
                    sprintf(
                        'None of the %d configured connection(s) crosses a network — each is a '
                            . 'local file or a local socket — so this deployment has no database '
                            . 'transport. There is nothing here to encrypt and nothing here that '
                            . 'was observed encrypted.',
                        count($connections),
                    ),
                ),
                self::class,
            );
        }

        // Asking a file-backed engine what its session negotiated is how "SQLite is
        // a local file" became a measured encryption pass. The question is only put
        // to an engine that has a session to answer for.
        if ($connection !== null && TransportSecurity::crossesNetwork($connection->driver())) {
            // Null is not a negative answer — the server declined to say, or the
            // catalogue read failed — so it falls through to the config inspection
            // below rather than being reported as an unencrypted transport.
            $negotiated = TransportSecurity::negotiated($connection);

            if ($negotiated !== null) {
                return Observation::measured(
                    ObservationId::DatabaseTransportEncrypted,
                    Measurement::completed(
                        self::SESSION_SUBJECT,
                        [$negotiated->encrypted
                            ? ExecutedSubject::passed(self::SESSION_SUBJECT, $negotiated->detail)
                            : ExecutedSubject::failed(self::SESSION_SUBJECT, $negotiated->detail)],
                        $negotiated->detail,
                    ),
                    self::class,
                );
            }
        }

        $plaintext = $this->connectionsWithoutTls($config);

        if ($plaintext === []) {
            return Observation::declaredMet(
                ObservationId::DatabaseTransportEncrypted,
                sprintf(
                    'All %d networked connection(s) — %s — are configured for TLS, but the server '
                        . 'was not asked what it negotiated; this records the request, not the session.',
                    count($networked),
                    implode(', ', $networked),
                ),
                self::class,
            );
        }

        return Observation::declaredUnmet(
            ObservationId::DatabaseTransportEncrypted,
            sprintf(
                'Connection(s) %s cross a network with no TLS setting: credentials and rows travel in the clear.',
                implode(', ', $plaintext),
            ),
            self::class,
        );
    }

    /**
     * Whether nothing this deployment dials travels in the clear.
     *
     * Deliberately NOT the same question as {@see observe()}: this one is a
     * fail-closed precondition for {@see \Pulsar\Compliance\Verification\RuntimeVerifier},
     * so "there is no networked connection" and "every networked connection is
     * configured for TLS" are both acceptable answers to it — nothing is
     * travelling unprotected in either case. It is not evidence and is never
     * turned into an {@see Observation}: as evidence the two cases are different
     * facts, and {@see observe()} keeps them apart.
     */
    #[NoDiscard]
    public function configuredForTls(?DatabaseConfig $config): bool
    {
        return $config === null || $this->connectionsWithoutTls($config) === [];
    }

    /**
     * Names of the connections that actually cross a network.
     *
     * The population an absence of transport is claimed over. A connection whose
     * engine has no network transport, and one that reaches the server through
     * local IPC, are both left out — there is no transport for TLS to protect —
     * which is exactly why an empty result here means "no subject" and not "all
     * clear".
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function networkedConnections(DatabaseConfig $config): array
    {
        $networked = [];

        foreach ($config->connections as $connection) {
            if (!TransportSecurity::crossesNetwork($connection->driver) || self::isLocalIpcConnection($connection)) {
                continue;
            }

            $networked[] = $connection->name;
        }

        return $networked;
    }

    /**
     * Names of the connections that cross a network without TLS.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function connectionsWithoutTls(DatabaseConfig $config): array
    {
        $plaintext = [];

        foreach ($config->connections as $connection) {
            if (!TransportSecurity::crossesNetwork($connection->driver) || self::isLocalIpcConnection($connection)) {
                continue;
            }

            if (!self::connectionUsesTls($connection)) {
                $plaintext[] = $connection->name;
            }
        }

        return $plaintext;
    }

    /**
     * Whether the connection reaches the server through local inter-process
     * communication rather than a network, in which case there is no transport to
     * encrypt.
     *
     * A path-like host is the portable spelling: libpq treats `host=/var/run/
     * postgresql` as a socket directory, and an empty host lets the client library
     * fall back to its default socket. A Windows named pipe is likewise local IPC.
     *
     * Loopback TCP (`127.0.0.1`, `::1`, and the driver-dependent `localhost`) is
     * deliberately NOT exempted: it is a real TCP connection, and which of socket
     * or TCP `localhost` selects depends on the client library.
     */
    public static function isLocalIpcConnection(ConnectionConfig $connection): bool
    {
        $host = $connection->host;

        return $host === ''
            || str_starts_with($host, '/')
            || str_starts_with($host, '\\\\');
    }

    /**
     * Recognize a TLS-enabled connection from its PDO options.
     *
     * Accepts the `sslmode`/`ssl_mode` spellings at a strength of at least "require"
     * on the engines that actually read them, and the presence of any PDO MySQL SSL
     * attribute (CA, client cert or key), which cannot be set without TLS being used.
     * The PDO constants are resolved defensively because pdo_mysql may not be loaded.
     */
    public static function connectionUsesTls(ConnectionConfig $connection): bool
    {
        $options = $connection->options;

        // Counting an sslmode entry on an engine whose DSN does not carry it is what
        // made this check report TLS on a plaintext connection: the key never reaches
        // PDO, which indexes driver options by integer constant and drops string keys.
        if (TransportSecurity::honoursSslModeOption($connection->driver)) {
            foreach (['ssl_mode', 'sslmode'] as $key) {
                /** @var mixed $mode */
                $mode = $options[$key] ?? null;

                if (is_string($mode) && in_array(strtolower($mode), self::TLS_SSL_MODES, true)) {
                    return true;
                }
            }
        }

        // PHP 8.5 renamed these to the Pdo\Mysql enum-style constants and deprecated
        // the PDO::MYSQL_ATTR_* spellings. The underlying integer values are
        // unchanged, so resolving the modern names also matches an option array a
        // project wrote with the legacy constants.
        foreach (['Pdo\Mysql::ATTR_SSL_CA', 'Pdo\Mysql::ATTR_SSL_CERT', 'Pdo\Mysql::ATTR_SSL_KEY'] as $constant) {
            if (!defined($constant)) {
                continue;
            }

            /** @var mixed $attribute */
            $attribute = constant($constant);

            if ((is_int($attribute) || is_string($attribute)) && isset($options[$attribute])) {
                return true;
            }
        }

        return false;
    }
}
