<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Driver\DatabaseDriver;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;

#[CoversClass(DatabaseDriver::class)]
final class DatabaseDriverTest extends TestCase
{
    use CacheDriverContractTrait;

    private PdoConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
            options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        );
        $this->driver = $this->createDriver();
    }

    protected function createDriver(): CacheDriverInterface
    {
        return new DatabaseDriver($this->connection);
    }

    #[Test]
    public function incrementInitializesToStep(): void
    {
        $result = $this->driver->increment('counter', 3);

        self::assertSame(3, $result);
    }

    #[Test]
    public function incrementAddsToExistingValue(): void
    {
        $this->driver->set('counter', '10', null);

        $result = $this->driver->increment('counter', 5);

        self::assertSame(15, $result);
    }

    #[Test]
    public function decrementSubtractsFromExistingValue(): void
    {
        $this->driver->set('counter', '10', null);

        $result = $this->driver->decrement('counter', 3);

        self::assertSame(7, $result);
    }

    #[Test]
    public function capabilitiesAllTrue(): void
    {
        $capabilities = $this->driver->capabilities();

        self::assertTrue($capabilities->supportsTagsStrict);
        self::assertTrue($capabilities->supportsLocksFencing);
        self::assertTrue($capabilities->supportsBinary);
        self::assertTrue($capabilities->supportsAtomicIncrement);
    }

    #[Test]
    public function garbageCollectionDoesNotBreakReads(): void
    {
        $this->driver->set('persistent', 'value', null);

        // Perform many reads to trigger probabilistic GC
        for ($i = 0; $i < 200; $i++) {
            $this->driver->get('persistent');
        }

        self::assertSame('value', $this->driver->get('persistent'));
    }

    #[Test]
    public function nameReturnsDatabase(): void
    {
        self::assertSame('database', $this->driver->name());
    }
}
