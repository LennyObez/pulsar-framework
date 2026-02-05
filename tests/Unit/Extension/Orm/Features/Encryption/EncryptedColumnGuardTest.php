<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Exception\EncryptedColumnQueryException;
use Pulsar\Extension\Orm\Features\Encryption\EncryptedColumnGuard;
use stdClass;

#[CoversClass(EncryptedColumnGuard::class)]
final class EncryptedColumnGuardTest extends TestCase
{
    private MetadataRegistryInterface&Stub $registry;
    private EncryptedColumnGuard $guard;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(MetadataRegistryInterface::class);
        $this->guard = new EncryptedColumnGuard($this->registry);
    }

    private function buildMetadata(ColumnMetadata ...$columns): EntityMetadata
    {
        $colMap = [];
        $pk = null;
        foreach ($columns as $col) {
            $colMap[$col->propertyName] = $col;
            if ($col->isPrimaryKey) {
                $pk = $col;
            }
        }
        $pk ??= new ColumnMetadata('id', 'id', ColumnType::BigInt, isPrimaryKey: true);

        return new EntityMetadata(
            entityClass: stdClass::class,
            tableName: 'entities',
            schema: null,
            primaryKey: $pk,
            columns: $colMap,
            relations: [],
            hasTimestamps: false,
            createdAtColumn: null,
            updatedAtColumn: null,
            hasSoftDelete: false,
            softDeleteColumn: null,
            isTenantScoped: false,
            tenantColumn: null,
            isTenantShared: false,
            versionProperty: null,
            encryptedColumns: [],
        );
    }

    #[Test]
    public function guardWhereAllowsNonEncryptedColumn(): void
    {
        $metadata = $this->buildMetadata(
            new ColumnMetadata('name', 'name', ColumnType::String),
        );
        $this->registry->method('get')->willReturn($metadata);

        $this->guard->guardWhere(stdClass::class, 'name');

        // No exception means pass
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function guardWhereAllowsEncryptedColumnWithBlindIndex(): void
    {
        $metadata = $this->buildMetadata(
            new ColumnMetadata(
                'ssn',
                'ssn',
                ColumnType::String,
                encrypted: true,
                blindIndexColumn: 'ssn_bidx',
                blindIndexHashLength: 32,
            ),
        );
        $this->registry->method('get')->willReturn($metadata);

        $this->guard->guardWhere(stdClass::class, 'ssn');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function guardWhereThrowsForEncryptedColumnWithoutBlindIndex(): void
    {
        $metadata = $this->buildMetadata(
            new ColumnMetadata(
                'ssn',
                'ssn',
                ColumnType::String,
                encrypted: true,
            ),
        );
        $this->registry->method('get')->willReturn($metadata);

        $this->expectException(EncryptedColumnQueryException::class);
        $this->expectExceptionMessageMatches('/blind index/i');

        $this->guard->guardWhere(stdClass::class, 'ssn');
    }

    #[Test]
    public function guardWhereAllowsUnknownColumn(): void
    {
        $metadata = $this->buildMetadata();
        $this->registry->method('get')->willReturn($metadata);

        $this->guard->guardWhere(stdClass::class, 'nonexistent');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function guardWhereResolvesColumnByPropertyName(): void
    {
        $metadata = $this->buildMetadata(
            new ColumnMetadata(
                'socialSecurityNumber',
                'ssn',
                ColumnType::String,
                encrypted: true,
            ),
        );
        $this->registry->method('get')->willReturn($metadata);

        $this->expectException(EncryptedColumnQueryException::class);

        $this->guard->guardWhere(stdClass::class, 'socialSecurityNumber');
    }

    #[Test]
    public function guardWhereResolvesColumnByColumnName(): void
    {
        $metadata = $this->buildMetadata(
            new ColumnMetadata(
                'socialSecurityNumber',
                'ssn',
                ColumnType::String,
                encrypted: true,
            ),
        );
        $this->registry->method('get')->willReturn($metadata);

        $this->expectException(EncryptedColumnQueryException::class);

        $this->guard->guardWhere(stdClass::class, 'ssn');
    }

    #[Test]
    public function guardOrderByAllowsNonEncryptedColumn(): void
    {
        $metadata = $this->buildMetadata(
            new ColumnMetadata('name', 'name', ColumnType::String),
        );
        $this->registry->method('get')->willReturn($metadata);

        $this->guard->guardOrderBy(stdClass::class, 'name');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function guardOrderByThrowsForEncryptedColumn(): void
    {
        $metadata = $this->buildMetadata(
            new ColumnMetadata(
                'ssn',
                'ssn',
                ColumnType::String,
                encrypted: true,
                blindIndexColumn: 'ssn_bidx',
            ),
        );
        $this->registry->method('get')->willReturn($metadata);

        $this->expectException(EncryptedColumnQueryException::class);
        $this->expectExceptionMessageMatches('/ORDER BY/i');

        $this->guard->guardOrderBy(stdClass::class, 'ssn');
    }

    #[Test]
    public function guardOrderByThrowsEvenWithBlindIndex(): void
    {
        // Even if a blind index exists, ORDER BY on encrypted columns is not allowed
        $metadata = $this->buildMetadata(
            new ColumnMetadata(
                'email',
                'email',
                ColumnType::String,
                encrypted: true,
                blindIndexColumn: 'email_bidx',
                blindIndexHashLength: 32,
            ),
        );
        $this->registry->method('get')->willReturn($metadata);

        $this->expectException(EncryptedColumnQueryException::class);

        $this->guard->guardOrderBy(stdClass::class, 'email');
    }

    #[Test]
    public function guardOrderByAllowsUnknownColumn(): void
    {
        $metadata = $this->buildMetadata();
        $this->registry->method('get')->willReturn($metadata);

        $this->guard->guardOrderBy(stdClass::class, 'nonexistent');

        $this->addToAssertionCount(1);
    }
}
