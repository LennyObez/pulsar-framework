<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Tenancy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Contracts\TenantScopeInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Hydration\EntityHydrator;
use Pulsar\Extension\Orm\Features\Persistence\AuditingPersister;
use Pulsar\Extension\Orm\Features\Persistence\GenericRepository;
use Pulsar\Extension\Orm\Features\Tenancy\TenantColumnResolver;
use Pulsar\Extension\Orm\Features\Tenancy\TenantScopeApplier;

/**
 * End-to-end proof (real in-memory SQLite) that reads through a repository
 * wired with a TenantScopeApplier cannot return another tenant's rows —
 * find(), findOneBy()/findBy(), count(), and exists() are all constrained to
 * the active tenant. Guards against cross-tenant IDOR on the read path.
 */
final class TenantReadIsolationTest extends TestCase
{
    private PdoConnection $connection;

    protected function setUp(): void
    {
        $this->connection = PdoConnection::fromConfig(new ConnectionConfig(
            name: 'rc2_read',
            driver: Driver::SQLite,
            host: '',
            port: 0,
            database: ':memory:',
            username: '',
            password: '',
            charset: 'utf8mb4',
            collation: 'utf8mb4_unicode_ci',
            options: [],
        ));

        $this->connection->execute(
            'CREATE TABLE orders (id INTEGER PRIMARY KEY, name TEXT NOT NULL, tenant_id TEXT NOT NULL)',
        );
        $this->connection->execute(
            'INSERT INTO orders (id, name, tenant_id) VALUES (:id, :name, :tenant)',
            ['id' => 1, 'name' => 'Acme order', 'tenant' => 'acme'],
        );
        $this->connection->execute(
            'INSERT INTO orders (id, name, tenant_id) VALUES (:id, :name, :tenant)',
            ['id' => 2, 'name' => 'Globex order', 'tenant' => 'globex'],
        );
    }

    #[Test]
    public function findReturnsOnlyTheActiveTenantsRow(): void
    {
        $repo = $this->repositoryForTenant('acme');

        $own = $repo->find(1);
        self::assertInstanceOf(OrderReadEntity::class, $own);
        self::assertSame('Acme order', $own->name);

        // Row 2 belongs to globex: the tenant predicate excludes it even though
        // its primary key is known — a direct-object-reference attack fails.
        self::assertNull($repo->find(2), 'a foreign tenant row must not be reachable by id');
    }

    #[Test]
    public function findByAndCountSeeOnlyTheActiveTenantsRows(): void
    {
        $repo = $this->repositoryForTenant('acme');

        $rows = $repo->findBy([]);
        self::assertCount(1, $rows);
        self::assertInstanceOf(OrderReadEntity::class, $rows[0]);
        self::assertSame('Acme order', $rows[0]->name);

        self::assertSame(1, $repo->count());
        self::assertTrue($repo->exists(1));
        self::assertFalse($repo->exists(2), 'exists() must not leak a foreign tenant row');
    }

    #[Test]
    public function switchingTheActiveTenantSwitchesVisibleRows(): void
    {
        $globex = $this->repositoryForTenant('globex');

        $own = $globex->find(2);
        self::assertInstanceOf(OrderReadEntity::class, $own);
        self::assertSame('Globex order', $own->name);

        self::assertNull($globex->find(1), 'the other tenant\'s row must not be reachable');
        self::assertSame(1, $globex->count());
    }

    #[Test]
    public function withoutATenantScopeEveryRowIsVisible(): void
    {
        // Control: the same data with no TenantScopeApplier (single-tenant app)
        // returns both rows — proving the scoping above is the applier's doing,
        // not an artifact of the fixture.
        $repo = $this->repository(null);

        self::assertInstanceOf(OrderReadEntity::class, $repo->find(1));
        self::assertInstanceOf(OrderReadEntity::class, $repo->find(2));
        self::assertSame(2, $repo->count());
    }

    /**
     * @return GenericRepository<OrderReadEntity>
     */
    private function repositoryForTenant(string $tenantId): GenericRepository
    {
        $tenantScope = $this->createStub(TenantScopeInterface::class);
        $tenantScope->method('isActive')->willReturn(true);
        $tenantScope->method('currentTenantId')->willReturn($tenantId);

        $applier = new TenantScopeApplier(
            $tenantScope,
            new TenantColumnResolver(OrmConfig::fromArray(['tenant_column' => 'tenant_id'])),
        );

        return $this->repository($applier);
    }

    /**
     * @return GenericRepository<OrderReadEntity>
     */
    private function repository(?TenantScopeApplier $applier): GenericRepository
    {
        $registry = $this->registryFor($this->tenantScopedMetadata());
        $hydrator = new EntityHydrator($registry);
        $persister = new AuditingPersister($this->connection, $registry, new EntityDehydrator($registry));

        return new GenericRepository(
            $this->connection,
            $registry,
            $hydrator,
            $persister,
            OrderReadEntity::class,
            $applier,
        );
    }

    private function registryFor(EntityMetadata $metadata): MetadataRegistryInterface&Stub
    {
        $registry = $this->createStub(MetadataRegistryInterface::class);
        $registry->method('get')->willReturn($metadata);

        return $registry;
    }

    private function tenantScopedMetadata(): EntityMetadata
    {
        $idCol = new ColumnMetadata(
            propertyName: 'id',
            columnName: 'id',
            type: ColumnType::Integer,
            nullable: false,
            isPrimaryKey: true,
            autoIncrement: true,
            isVersion: false,
            encrypted: false,
            blindIndexColumn: null,
            blindIndexHashLength: null,
            insertable: true,
            updatable: false,
            casterClass: null,
        );

        $nameCol = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: ColumnType::String,
            nullable: false,
            isPrimaryKey: false,
            autoIncrement: false,
            isVersion: false,
            encrypted: false,
            blindIndexColumn: null,
            blindIndexHashLength: null,
            insertable: true,
            updatable: true,
            casterClass: null,
        );

        return new EntityMetadata(
            entityClass: OrderReadEntity::class,
            tableName: 'orders',
            schema: null,
            primaryKey: $idCol,
            columns: ['id' => $idCol, 'name' => $nameCol],
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: true,
            tenantColumn: 'tenant_id',
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );
    }
}

/**
 * Read-side fixture. The tenant column is carried in metadata (tenantColumn)
 * and the physical table, not as a mapped property — exactly as a compiled
 * #[TenantScoped] entity expresses it.
 *
 * @internal
 */
final class OrderReadEntity
{
    public int $id;

    public string $name;
}
