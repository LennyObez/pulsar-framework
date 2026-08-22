<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Encryption;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Exception\EncryptedColumnQueryException;
use Pulsar\Extension\Orm\Features\Encryption\EncryptedColumnGuard;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\SecureEntity;

final class EncryptedColumnGuardTest extends TestCase
{
    private function createMetadata(bool $encrypted, ?string $blindIndex): EntityMetadata
    {
        $pk = new ColumnMetadata('id', 'id', ColumnType::BigInt, isPrimaryKey: true);
        $col = new ColumnMetadata(
            propertyName: 'secret',
            columnName: 'secret_enc',
            type: ColumnType::Binary,
            encrypted: $encrypted,
            blindIndexColumn: $blindIndex,
        );

        return new EntityMetadata(
            entityClass: SecureEntity::class,
            tableName: 'secure',
            schema: null,
            primaryKey: $pk,
            columns: ['id' => $pk, 'secret' => $col],
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
            encryptedColumns: $encrypted ? ['secret'] : [],
        );
    }

    private function createGuard(EntityMetadata $metadata): EncryptedColumnGuard
    {
        $registry = $this->createStub(MetadataRegistryInterface::class);
        $registry->method('get')->willReturn($metadata);

        return new EncryptedColumnGuard($registry);
    }

    #[Test]
    public function guardWhereAllowsNonEncryptedColumn(): void
    {
        $guard = $this->createGuard($this->createMetadata(false, null));

        $guard->guardWhere(SecureEntity::class, 'secret_enc');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function guardWhereAllowsEncryptedColumnWithBlindIndex(): void
    {
        $guard = $this->createGuard($this->createMetadata(true, 'secret_idx'));

        $guard->guardWhere(SecureEntity::class, 'secret_enc');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function guardWhereThrowsForEncryptedColumnWithoutBlindIndex(): void
    {
        $guard = $this->createGuard($this->createMetadata(true, null));

        $this->expectException(EncryptedColumnQueryException::class);

        $guard->guardWhere(SecureEntity::class, 'secret_enc');
    }

    #[Test]
    public function guardWhereAllowsUnknownColumn(): void
    {
        $guard = $this->createGuard($this->createMetadata(true, null));

        $guard->guardWhere(SecureEntity::class, 'nonexistent');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function guardOrderByAllowsNonEncryptedColumn(): void
    {
        $guard = $this->createGuard($this->createMetadata(false, null));

        $guard->guardOrderBy(SecureEntity::class, 'secret_enc');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function guardOrderByThrowsForAnyEncryptedColumn(): void
    {
        $guard = $this->createGuard($this->createMetadata(true, 'secret_idx'));

        $this->expectException(EncryptedColumnQueryException::class);

        $guard->guardOrderBy(SecureEntity::class, 'secret_enc');
    }
}
