<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\DriverVariant;
use Pulsar\Database\Result;
use Pulsar\Database\Schema\SchemaCapabilities;

#[CoversClass(SchemaCapabilities::class)]
final class SchemaCapabilitiesTest extends TestCase
{
    #[Test]
    public function sqliteCapabilities(): void
    {
        $cap = new SchemaCapabilities(Driver::SQLite);

        self::assertFalse($cap->supportsDropColumn()); // no connection, defaults false
        self::assertFalse($cap->supportsAlterColumnType());
        self::assertTrue($cap->supportsForeignKeySyntax());
        self::assertFalse($cap->foreignKeysEnforcedByDefault());
        self::assertFalse($cap->supportsTransactionalDdl());
        self::assertFalse($cap->supportsNativeEnum());
        self::assertFalse($cap->supportsAddForeignKey());
        self::assertFalse($cap->supportsDropForeignKey());
        self::assertFalse($cap->supportsUnsigned());
    }

    #[Test]
    public function pgsqlCapabilities(): void
    {
        $cap = new SchemaCapabilities(Driver::PostgreSQL);

        self::assertTrue($cap->supportsDropColumn());
        self::assertTrue($cap->supportsAlterColumnType());
        self::assertTrue($cap->supportsForeignKeySyntax());
        self::assertTrue($cap->foreignKeysEnforcedByDefault());
        self::assertTrue($cap->supportsTransactionalDdl());
        self::assertFalse($cap->supportsNativeEnum());
        self::assertTrue($cap->supportsAddForeignKey());
        self::assertTrue($cap->supportsDropForeignKey());
        self::assertFalse($cap->supportsUnsigned());
    }

    #[Test]
    public function mysqlCapabilities(): void
    {
        $cap = new SchemaCapabilities(Driver::MySQL);

        self::assertTrue($cap->supportsDropColumn());
        self::assertTrue($cap->supportsAlterColumnType());
        self::assertTrue($cap->supportsForeignKeySyntax());
        self::assertTrue($cap->foreignKeysEnforcedByDefault());
        self::assertFalse($cap->supportsTransactionalDdl());
        self::assertTrue($cap->supportsNativeEnum());
        self::assertTrue($cap->supportsAddForeignKey());
        self::assertTrue($cap->supportsDropForeignKey());
        self::assertTrue($cap->supportsUnsigned());
    }

    /**
     * With no connection to ask, a capability is reported absent rather than present.
     *
     * The two mistakes are not symmetrical. Reporting a capability absent costs a
     * fallback path; reporting one present emits SQL the server rejects — or, for CHECK
     * constraints on MySQL before 8.0.16, SQL the server accepts and silently discards,
     * leaving a schema that appears to carry a constraint it does not.
     */
    #[Test]
    public function versionGatedCapabilitiesAreFalseWithoutAConnectionToAsk(): void
    {
        $cap = new SchemaCapabilities(Driver::MySQL);

        self::assertFalse($cap->supportsCheckConstraints());
        self::assertFalse($cap->supportsWindowFunctions());
        self::assertFalse($cap->supportsCte());
    }

    #[Test]
    public function modernMySqlReportsTheCapabilitiesItHas(): void
    {
        $cap = new SchemaCapabilities(Driver::MySQL, $this->serverReporting('8.0.36'));

        self::assertTrue($cap->supportsCheckConstraints());
        self::assertTrue($cap->supportsWindowFunctions());
        self::assertTrue($cap->supportsCte());
    }

    /**
     * 5.7 is out of support upstream and still widely deployed in the domains this
     * framework targets. It parses CHECK and discards it, and rejects CTEs and window
     * functions outright.
     */
    #[Test]
    public function mySql57ReportsTheCapabilitiesItLacks(): void
    {
        $cap = new SchemaCapabilities(Driver::MySQL, $this->serverReporting('5.7.44-log'));

        self::assertFalse($cap->supportsCheckConstraints());
        self::assertFalse($cap->supportsWindowFunctions());
        self::assertFalse($cap->supportsCte());
    }

    /**
     * MariaDB shares the driver but not the version line: 10.2 is newer than MySQL 8.0,
     * so applying MySQL's floor to it would report every MariaDB server as incapable.
     */
    #[Test]
    public function mariaDbIsJudgedAgainstItsOwnVersionLine(): void
    {
        $capable = new SchemaCapabilities(Driver::MySQL, $this->serverReporting('10.5.18-MariaDB'));

        self::assertTrue($capable->supportsCheckConstraints());
        self::assertTrue($capable->supportsWindowFunctions());
        self::assertTrue($capable->supportsCte());

        $tooOld = new SchemaCapabilities(Driver::MySQL, $this->serverReporting('10.1.48-MariaDB'));

        self::assertFalse($tooOld->supportsCheckConstraints());
        self::assertFalse($tooOld->supportsCte());
    }

    /**
     * The variant comes from the live VERSION() string, not from the constructor.
     *
     * A caller that mislabels a MariaDB server as Standard would otherwise have its hint
     * believed over the server's own answer — and a hint that is wrong here reports a
     * capability the server does not have.
     */
    #[Test]
    public function theServersOwnAnswerBeatsTheVariantSuppliedByTheCaller(): void
    {
        $cap = new SchemaCapabilities(
            Driver::MySQL,
            $this->serverReporting('10.2.1-MariaDB'),
            DriverVariant::Standard,
        );

        // Under MySQL's 8.0.16 floor a "10.2.1" string would pass on the leading 10;
        // what makes this meaningful is the reverse case below.
        self::assertTrue($cap->supportsCheckConstraints());

        $old = new SchemaCapabilities(
            Driver::MySQL,
            $this->serverReporting('10.1.48-MariaDB'),
            DriverVariant::Standard,
        );

        self::assertFalse($old->supportsCheckConstraints());
    }

    /**
     * Percona is MySQL, and its build suffix must not be weighed as a version part.
     */
    #[Test]
    public function perconaIsTreatedAsMySqlAndItsBuildSuffixIgnored(): void
    {
        $cap = new SchemaCapabilities(Driver::MySQL, $this->serverReporting('8.0.35-26-Percona Server'));

        self::assertTrue($cap->supportsCheckConstraints());
        self::assertTrue($cap->supportsCte());
    }

    /**
     * The server is asked once, however many capabilities are read.
     */
    #[Test]
    public function theVersionIsQueriedOnlyOnce(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->willReturn(Result::fromArrays([['version' => '8.0.36']]));

        $cap = new SchemaCapabilities(Driver::MySQL, $connection);

        $cap->supportsCheckConstraints();
        $cap->supportsWindowFunctions();
        $cap->supportsCte();
    }

    private function serverReporting(string $version): ConnectionInterface
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(Result::fromArrays([['version' => $version]]));

        return $connection;
    }

    #[Test]
    public function toArrayReturnsAllKeys(): void
    {
        $cap = new SchemaCapabilities(Driver::SQLite);
        $array = $cap->toArray();

        self::assertArrayHasKey('supportsDropColumn', $array);
        self::assertArrayHasKey('supportsAlterColumnType', $array);
        self::assertArrayHasKey('supportsForeignKeys', $array);
        self::assertArrayHasKey('supportsTransactionalDdl', $array);
        self::assertArrayHasKey('supportsNativeEnum', $array);
        self::assertArrayHasKey('supportsAddForeignKey', $array);
        self::assertArrayHasKey('supportsDropForeignKey', $array);
        self::assertArrayHasKey('supportsUnsigned', $array);
    }
}
