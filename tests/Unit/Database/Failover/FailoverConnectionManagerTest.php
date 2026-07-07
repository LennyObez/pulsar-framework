<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Failover;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Failover\FailoverConnectionManager;
use Pulsar\Database\Failover\FailoverStateStore;
use Pulsar\Database\PdoConnection;

#[CoversClass(FailoverConnectionManager::class)]
final class FailoverConnectionManagerTest extends TestCase
{
    #[Test]
    public function delegatesToInnerWhenNoFailoverIsActive(): void
    {
        $innerConnection = $this->createStub(ConnectionInterface::class);
        $inner = $this->spyInner($innerConnection);
        $store = $this->store(null);

        $manager = new FailoverConnectionManager($inner, $store, $this->config());

        self::assertSame($innerConnection, $manager->connection());
    }

    #[Test]
    public function connectsToThePromotedEndpointWhenFailoverIsActive(): void
    {
        $innerConnection = $this->createStub(ConnectionInterface::class);
        $inner = $this->spyInner($innerConnection);
        $store = $this->store('10.0.0.2');

        $manager = new FailoverConnectionManager($inner, $store, $this->config());

        $connection = $manager->connection();

        // A real failover connection is built (not the inner primary). The sqlite
        // driver ignores the host, so the promoted endpoint connects in-memory.
        self::assertNotSame($innerConnection, $connection);
        self::assertInstanceOf(PdoConnection::class, $connection);
        self::assertSame(0, $inner->defaultCalls, 'the inner primary must not be used while a failover is active');
    }

    #[Test]
    public function reusesTheFailoverConnectionForTheSameEndpoint(): void
    {
        $inner = $this->spyInner($this->createStub(ConnectionInterface::class));
        $manager = new FailoverConnectionManager($inner, $this->store('10.0.0.2'), $this->config());

        self::assertSame($manager->connection(), $manager->connection());
    }

    #[Test]
    public function nonDefaultConnectionsAlwaysDelegate(): void
    {
        $readConnection = $this->createStub(ConnectionInterface::class);
        $inner = $this->spyInner($this->createStub(ConnectionInterface::class), $readConnection);
        // Failover is active, but a named (replica) connection must still delegate.
        $manager = new FailoverConnectionManager($inner, $this->store('10.0.0.2'), $this->config());

        self::assertSame($readConnection, $manager->connection('read'));
    }

    private function config(): DatabaseConfig
    {
        $sqlite = new ConnectionConfig(
            name: 'sqlite',
            driver: Driver::SQLite,
            host: '127.0.0.1',
            port: 0,
            database: ':memory:',
            username: '',
            password: '',
            charset: 'utf8',
            collation: 'utf8',
            options: [],
        );

        return new DatabaseConfig(
            defaultConnection: 'sqlite',
            connections: ['sqlite' => $sqlite],
            migrationsTable: 'migrations',
            migrationsPath: '',
        );
    }

    /**
     * @return FailoverStateStore
     */
    private function store(?string $endpoint): FailoverStateStore
    {
        return new class ($endpoint) implements FailoverStateStore {
            public function __construct(private ?string $endpoint) {}

            public function currentEndpoint(): ?string
            {
                return $this->endpoint;
            }

            public function recordFailover(string $endpoint): void
            {
                $this->endpoint = $endpoint;
            }

            public function clear(): void
            {
                $this->endpoint = null;
            }
        };
    }

    /**
     * An inner manager that returns fixed connections and counts default-connection
     * acquisitions, so a test can prove the primary was (not) consulted.
     */
    private function spyInner(
        ConnectionInterface $default,
        ?ConnectionInterface $named = null,
    ): SpyConnectionManager {
        return new SpyConnectionManager($default, $named);
    }
}

/**
 * @internal Test double: returns fixed connections and counts default-connection
 * acquisitions so a test can prove the primary was (not) consulted.
 */
final class SpyConnectionManager implements ConnectionManagerInterface
{
    public int $defaultCalls = 0;

    public function __construct(
        private readonly ConnectionInterface $default,
        private readonly ?ConnectionInterface $named = null,
    ) {}

    public function connection(?string $name = null): ConnectionInterface
    {
        if ($name === null || $name === 'sqlite') {
            $this->defaultCalls++;

            return $this->default;
        }

        return $this->named ?? $this->default;
    }

    public function getDefaultConnectionName(): string
    {
        return 'sqlite';
    }

    public function disconnect(?string $name = null): void {}
}
