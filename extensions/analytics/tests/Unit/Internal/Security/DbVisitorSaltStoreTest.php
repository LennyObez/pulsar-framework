<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Analytics\Internal\Security\DbVisitorSaltStore;

use function ctype_xdigit;
use function strlen;

#[CoversClass(DbVisitorSaltStore::class)]
final class DbVisitorSaltStoreTest extends TestCase
{
    private PdoConnection $connection;
    private DbVisitorSaltStore $store;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        // Build the table from the real migration, so the test also proves the
        // DDL adapts to SQLite.
        $migration = require __DIR__ . '/../../../../src/Migration/20260716000001_create_analytics_visitor_salts.php';
        self::assertInstanceOf(MigrationInterface::class, $migration);
        $migration->up($this->connection);

        $this->store = new DbVisitorSaltStore($this->connection);
    }

    #[Test]
    public function saltForDayCreatesAHexSalt(): void
    {
        $salt = $this->store->saltForDay(20000);

        self::assertSame(64, strlen($salt));
        self::assertTrue(ctype_xdigit($salt));
    }

    #[Test]
    public function saltForDayIsStableWithinTheSameDay(): void
    {
        $first = $this->store->saltForDay(20000);
        $second = $this->store->saltForDay(20000);

        // The whole point: one visitor, one hash per day. A second call must
        // return the stored salt, never mint a fresh one.
        self::assertSame($first, $second);
    }

    #[Test]
    public function differentDaysGetIndependentSalts(): void
    {
        $dayA = $this->store->saltForDay(20000);
        $dayB = $this->store->saltForDay(20001);

        self::assertNotSame($dayA, $dayB);
    }

    #[Test]
    public function existingSaltForDayReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->store->existingSaltForDay(19999));
    }

    #[Test]
    public function existingSaltForDayReturnsTheStoredSaltWithoutCreating(): void
    {
        $created = $this->store->saltForDay(20000);

        self::assertSame($created, $this->store->existingSaltForDay(20000));
        // A day never written stays absent — no fabrication.
        self::assertNull($this->store->existingSaltForDay(20005));
    }

    #[Test]
    public function purgeOlderThanDeletesOnlyDaysBeforeTheCutoff(): void
    {
        $this->store->saltForDay(20000);
        $this->store->saltForDay(20001);
        $this->store->saltForDay(20002);

        $deleted = $this->store->purgeOlderThan(20002);

        self::assertSame(2, $deleted);
        // Cutoff is exclusive: 20002 survives, everything older is gone —
        // and gone means unrecomputable (forward secrecy).
        self::assertNull($this->store->existingSaltForDay(20000));
        self::assertNull($this->store->existingSaltForDay(20001));
        self::assertNotNull($this->store->existingSaltForDay(20002));
    }
}
