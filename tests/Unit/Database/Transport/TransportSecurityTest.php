<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\InvalidDsnComponentException;
use Pulsar\Database\Result;
use Pulsar\Database\Transport\NegotiatedTransport;
use Pulsar\Database\Transport\TransportSecurity;
use Pulsar\Tests\Unit\Database\Stub\InMemoryConnection;
use RuntimeException;

use function str_contains;

/**
 * The engine facts a compliance assessor needs, and the one answer that is not a fact.
 *
 * Naming `Driver::MySQL` here is the test's purpose rather than debt: the point of
 * moving this dispatch into `src/Database` is that exactly one place has to change when
 * an engine is added, and only a per-engine test can show that place is right.
 */
#[CoversClass(TransportSecurity::class)]
#[CoversClass(NegotiatedTransport::class)]
final class TransportSecurityTest extends TestCase
{
    /**
     * @return iterable<string, array{Driver, bool}>
     */
    public static function networkTransport(): iterable
    {
        yield 'MySQL talks to a server' => [Driver::MySQL, true];
        yield 'PostgreSQL talks to a server' => [Driver::PostgreSQL, true];
        yield 'SQLite is a file' => [Driver::SQLite, false];
    }

    #[Test]
    #[DataProvider('networkTransport')]
    public function itKnowsWhichEnginesHaveATransportToEncrypt(Driver $driver, bool $expected): void
    {
        self::assertSame($expected, TransportSecurity::crossesNetwork($driver));
    }

    /**
     * @return iterable<string, array{Driver, bool}>
     */
    public static function sslModeOption(): iterable
    {
        yield 'PostgreSQL lifts sslmode into the DSN' => [Driver::PostgreSQL, true];
        yield 'MySQL never sees a string option key' => [Driver::MySQL, false];
        yield 'SQLite has no DSN parameter for it' => [Driver::SQLite, false];
    }

    #[Test]
    #[DataProvider('sslModeOption')]
    public function itKnowsWhereAnSslModeOptionActuallyArrives(Driver $driver, bool $expected): void
    {
        self::assertSame($expected, TransportSecurity::honoursSslModeOption($driver));
    }

    /**
     * The answer above has to agree with the DSN builder that enforces it, or one of the
     * two is lying about the same connection.
     */
    #[Test]
    #[DataProvider('sslModeOption')]
    public function theSslModeAnswerAgreesWithTheDsnBuilder(Driver $driver, bool $expected): void
    {
        $accepted = true;

        try {
            $driver->buildDsn('db', $driver->defaultPort(), 'app', sslMode: 'require');
        } catch (InvalidDsnComponentException) {
            $accepted = false;
        }

        self::assertSame($expected, $accepted);
    }

    /**
     * The inversion this method used to publish.
     *
     * It answered `encrypted: true` with the detail "SQLite is a local file; the
     * session has no network transport to encrypt" — a sentence saying there is no
     * transport and a flag saying the transport is encrypted. The compliance
     * assessor read the flag, and a deployment that encrypts nothing carried PCI
     * Req 2.3 at grade Measured. A boolean cannot say "there is nothing here to
     * encrypt", so the method declines to answer and the caller must ask
     * {@see TransportSecurity::crossesNetwork()} first.
     */
    #[Test]
    public function aLocalFileHasNoTransportToNegotiateAndSaysSoByDeclining(): void
    {
        self::assertNull(
            TransportSecurity::negotiated(new InMemoryConnection(Driver::SQLite)),
            'A file-backed engine must not report an encrypted session: it has no session. '
                . 'Returning encrypted: true here is how "there is no transport" was read as '
                . '"the transport is protected".',
        );
        self::assertFalse(TransportSecurity::crossesNetwork(Driver::SQLite));
    }

    #[Test]
    public function postgresReportsTheNegotiatedProtocolAndCipher(): void
    {
        $connection = new InMemoryConnection(Driver::PostgreSQL);
        $connection->enqueueQueryResult(Result::fromArrays([
            ['ssl' => 't', 'version' => 'TLSv1.3', 'cipher' => 'TLS_AES_256_GCM_SHA384'],
        ]));

        $negotiated = TransportSecurity::negotiated($connection);

        self::assertNotNull($negotiated);
        self::assertTrue($negotiated->encrypted);
        self::assertStringContainsString('TLSv1.3', $negotiated->detail);
        self::assertStringContainsString('TLS_AES_256_GCM_SHA384', $negotiated->detail);
    }

    /**
     * PDO surfaces a PostgreSQL boolean as `t`, `1`, an int or a real bool depending on
     * how the driver was built. Reading only one spelling reports every session on the
     * other three as plaintext.
     */
    #[Test]
    #[DataProvider('affirmativeSslColumn')]
    public function postgresAcceptsEverySpellingOfATrueBoolean(mixed $ssl): void
    {
        $connection = new InMemoryConnection(Driver::PostgreSQL);
        $connection->enqueueQueryResult(Result::fromArrays([
            ['ssl' => $ssl, 'version' => 'TLSv1.3', 'cipher' => 'AESGCM'],
        ]));

        $negotiated = TransportSecurity::negotiated($connection);

        self::assertNotNull($negotiated);
        self::assertTrue($negotiated->encrypted);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function affirmativeSslColumn(): iterable
    {
        yield 'native bool' => [true];
        yield 'pgsql char' => ['t'];
        yield 'int one' => [1];
        yield 'string one' => ['1'];
    }

    #[Test]
    public function postgresNamesThePlaintextSessionRatherThanReturningABareFalse(): void
    {
        $connection = new InMemoryConnection(Driver::PostgreSQL);
        $connection->enqueueQueryResult(Result::fromArrays([
            ['ssl' => 'f', 'version' => null, 'cipher' => null],
        ]));

        $negotiated = TransportSecurity::negotiated($connection);

        self::assertNotNull($negotiated);
        self::assertFalse($negotiated->encrypted);
        self::assertNotSame('', $negotiated->detail);
    }

    #[Test]
    public function mysqlReadsTheSessionCipher(): void
    {
        $connection = new InMemoryConnection(Driver::MySQL);
        $connection->enqueueQueryResult(Result::fromArrays([
            ['Variable_name' => 'Ssl_cipher', 'Value' => 'ECDHE-RSA-AES128-GCM-SHA256'],
        ]));

        $negotiated = TransportSecurity::negotiated($connection);

        self::assertNotNull($negotiated);
        self::assertTrue($negotiated->encrypted);
        self::assertStringContainsString('ECDHE-RSA-AES128-GCM-SHA256', $negotiated->detail);
    }

    #[Test]
    public function anEmptyMysqlCipherIsAPlaintextSession(): void
    {
        $connection = new InMemoryConnection(Driver::MySQL);
        $connection->enqueueQueryResult(Result::fromArrays([
            ['Variable_name' => 'Ssl_cipher', 'Value' => ''],
        ]));

        $negotiated = TransportSecurity::negotiated($connection);

        self::assertNotNull($negotiated);
        self::assertFalse($negotiated->encrypted);
    }

    /**
     * A server that answers nothing is not a server answering "plaintext". The
     * distinction is the whole reason the return type is nullable: a caller that
     * collapsed the two would report a gap the deployment may not have.
     */
    #[Test]
    public function anEmptyResultSetIsNoAnswerRatherThanANegativeOne(): void
    {
        $connection = new InMemoryConnection(Driver::PostgreSQL);
        $connection->enqueueQueryResult(new Result([]));

        self::assertNull(TransportSecurity::negotiated($connection));
    }

    #[Test]
    public function aFailedCatalogueReadIsNoAnswerEither(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('query')->willThrowException(
            new RuntimeException('SHOW STATUS denied for this user'),
        );

        self::assertNull(TransportSecurity::negotiated($connection));
    }

    /**
     * The statement asked of MySQL is the one every server in the family answers.
     * `performance_schema.session_status` can be compiled out and
     * `information_schema.SESSION_STATUS` was removed in 8.0, so a catalogue read here
     * would degrade a Measured observation to a Declared one on real deployments.
     */
    #[Test]
    public function mysqlIsAskedThroughShowStatusRatherThanACatalogueTable(): void
    {
        $connection = new InMemoryConnection(Driver::MySQL);
        $connection->enqueueQueryResult(Result::fromArrays([
            ['Variable_name' => 'Ssl_cipher', 'Value' => 'AESGCM'],
        ]));

        self::assertNotNull(TransportSecurity::negotiated($connection));

        $executed = $connection->executedStatements();

        self::assertCount(1, $executed);
        self::assertStringStartsWith('SHOW STATUS LIKE', $executed[0]);
        self::assertFalse(
            str_contains($executed[0], 'schema'),
            'the probe must not depend on a catalogue table that a hardened server may lack',
        );
    }
}
