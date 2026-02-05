<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\TenancyConfig;
use Pulsar\Config\TenantDatabaseConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Tenancy\Exception\TenancyException;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantAwareConnectionManager;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\TenantDatabaseStrategy;
use Pulsar\Tenancy\TenantResolverStrategy;
use RuntimeException;

#[CoversClass(TenantAwareConnectionManager::class)]
final class TenantAwareConnectionManagerTest extends TestCase
{
    #[Test]
    public function delegatesToInnerManagerForDefaultConnectionWhenSharedStrategy(): void
    {
        $mockConnection = $this->createStub(ConnectionInterface::class);

        $inner = new class ($mockConnection) implements ConnectionManagerInterface {
            public function __construct(private readonly ConnectionInterface $conn) {}

            public function connection(?string $name = null): ConnectionInterface
            {
                return $this->conn;
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function disconnect(?string $name = null): void {}
        };

        $context = new TenantContext();
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            database: new TenantDatabaseConfig(
                strategy: TenantDatabaseStrategy::Shared,
            ),
        );

        $manager = new TenantAwareConnectionManager($inner, $context, $config);
        $connection = $manager->connection();

        self::assertSame($mockConnection, $connection);
    }

    #[Test]
    public function getTablePrefixReturnsTenantPrefixedStringWhenStrategyIsPrefix(): void
    {
        $inner = new class implements ConnectionManagerInterface {
            public function connection(?string $name = null): ConnectionInterface
            {
                throw new RuntimeException('Should not be called');
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function disconnect(?string $name = null): void {}
        };

        $context = new TenantContext();
        $context->set(new Tenant(id: 'acme', name: 'Acme Corp'));

        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            database: new TenantDatabaseConfig(
                strategy: TenantDatabaseStrategy::Prefix,
                prefixTemplate: 'tenant_{tenant_id}_',
            ),
        );

        $manager = new TenantAwareConnectionManager($inner, $context, $config);

        self::assertSame('tenant_acme_', $manager->getTablePrefix());
    }

    #[Test]
    public function getTablePrefixThrowsWhenNoTenantResolvedAndStrategyIsPrefix(): void
    {
        $inner = new class implements ConnectionManagerInterface {
            public function connection(?string $name = null): ConnectionInterface
            {
                throw new RuntimeException('Should not be called');
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function disconnect(?string $name = null): void {}
        };

        $context = new TenantContext();
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            database: new TenantDatabaseConfig(
                strategy: TenantDatabaseStrategy::Prefix,
            ),
        );

        $manager = new TenantAwareConnectionManager($inner, $context, $config);

        $this->expectException(TenancyException::class);

        $manager->getTablePrefix();
    }

    #[Test]
    public function connectionUsesTenantPrefixedNameForSeparateConnectionStrategy(): void
    {
        $mockConnection = $this->createStub(ConnectionInterface::class);

        $inner = new class ($mockConnection) implements ConnectionManagerInterface {
            public ?string $lastRequestedName = null;

            public function __construct(
                private readonly ConnectionInterface $conn,
            ) {}

            public function connection(?string $name = null): ConnectionInterface
            {
                $this->lastRequestedName = $name;

                return $this->conn;
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function disconnect(?string $name = null): void {}
        };

        $context = new TenantContext();
        $context->set(new Tenant(id: 'acme', name: 'Acme Corp'));

        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            database: new TenantDatabaseConfig(
                strategy: TenantDatabaseStrategy::SeparateConnection,
            ),
        );

        $manager = new TenantAwareConnectionManager($inner, $context, $config);
        $connection = $manager->connection();

        self::assertSame($mockConnection, $connection);
        self::assertSame('tenant_acme', $inner->lastRequestedName);
    }

    #[Test]
    public function connectionFallsBackToInnerWhenSeparateConnectionButNoTenantResolved(): void
    {
        $mockConnection = $this->createStub(ConnectionInterface::class);

        $inner = new class ($mockConnection) implements ConnectionManagerInterface {
            public ?string $lastRequestedName = null;

            public function __construct(
                private readonly ConnectionInterface $conn,
            ) {}

            public function connection(?string $name = null): ConnectionInterface
            {
                $this->lastRequestedName = $name;

                return $this->conn;
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function disconnect(?string $name = null): void {}
        };

        $context = new TenantContext();
        // No tenant set

        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            database: new TenantDatabaseConfig(
                strategy: TenantDatabaseStrategy::SeparateConnection,
            ),
        );

        $manager = new TenantAwareConnectionManager($inner, $context, $config);
        $manager->connection('myconn');

        self::assertSame('myconn', $inner->lastRequestedName);
    }

    #[Test]
    public function getTablePrefixReturnsEmptyForSharedStrategy(): void
    {
        $inner = new class implements ConnectionManagerInterface {
            public function connection(?string $name = null): ConnectionInterface
            {
                throw new RuntimeException('Should not be called');
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function disconnect(?string $name = null): void {}
        };

        $context = new TenantContext();
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            database: new TenantDatabaseConfig(
                strategy: TenantDatabaseStrategy::Shared,
            ),
        );

        $manager = new TenantAwareConnectionManager($inner, $context, $config);

        self::assertSame('', $manager->getTablePrefix());
    }

    #[Test]
    public function getTablePrefixReturnsEmptyForSeparateConnectionStrategy(): void
    {
        $inner = new class implements ConnectionManagerInterface {
            public function connection(?string $name = null): ConnectionInterface
            {
                throw new RuntimeException('Should not be called');
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function disconnect(?string $name = null): void {}
        };

        $context = new TenantContext();
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            database: new TenantDatabaseConfig(
                strategy: TenantDatabaseStrategy::SeparateConnection,
            ),
        );

        $manager = new TenantAwareConnectionManager($inner, $context, $config);

        self::assertSame('', $manager->getTablePrefix());
    }

    #[Test]
    public function disconnectDelegatesToInner(): void
    {
        $inner = new class implements ConnectionManagerInterface {
            public ?string $disconnectedName = null;

            public function connection(?string $name = null): ConnectionInterface
            {
                throw new RuntimeException('Should not be called');
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function disconnect(?string $name = null): void
            {
                $this->disconnectedName = $name ?? '__default__';
            }
        };

        $context = new TenantContext();
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
        );

        $manager = new TenantAwareConnectionManager($inner, $context, $config);
        $manager->disconnect('myconn');

        self::assertSame('myconn', $inner->disconnectedName);
    }

    #[Test]
    public function getDefaultConnectionNameDelegatesToInner(): void
    {
        $inner = new class implements ConnectionManagerInterface {
            public function connection(?string $name = null): ConnectionInterface
            {
                throw new RuntimeException('Should not be called');
            }

            public function getDefaultConnectionName(): string
            {
                return 'my_default';
            }

            public function disconnect(?string $name = null): void {}
        };

        $context = new TenantContext();
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
        );

        $manager = new TenantAwareConnectionManager($inner, $context, $config);

        self::assertSame('my_default', $manager->getDefaultConnectionName());
    }
}
