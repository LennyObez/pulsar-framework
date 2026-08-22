<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Persistence;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Exception\OptimisticLockException;
use Pulsar\Extension\Orm\Exception\TenantIsolationException;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Query\DeleteBuilder;
use Pulsar\Extension\Orm\Features\Query\InsertBuilder;
use Pulsar\Extension\Orm\Features\Query\UpdateBuilder;
use Pulsar\Extension\Orm\Features\Tenancy\TenantInsertEnricher;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use ReflectionClass;

use function sprintf;

/**
 * Persists entity changes to the database with audit trail logging.
 */
#[Internal]
final readonly class AuditingPersister
{
    public function __construct(
        private ConnectionInterface $connection,
        private MetadataRegistryInterface $metadataRegistry,
        private EntityDehydrator $dehydrator,
        private ?AuditLoggerInterface $auditLogger = null,
        private ?TenantInsertEnricher $tenantEnricher = null,
    ) {}

    /**
     * Insert a new entity.
     */
    public function insert(object $entity, MutationContext $context): void
    {
        $metadata = $this->metadataRegistry->get($entity::class);
        $values = $this->dehydrator->dehydrateForInsert($entity);

        // Enrich with tenant column if applicable
        if ($this->tenantEnricher !== null) {
            $values = $this->tenantEnricher->enrich($values, $metadata);
        }

        // Add timestamps
        if ($metadata->hasTimestamps && $metadata->createdAtColumn !== null) {
            $values[$metadata->createdAtColumn] = date('Y-m-d H:i:s');
            if ($metadata->updatedAtColumn !== null) {
                $values[$metadata->updatedAtColumn] = date('Y-m-d H:i:s');
            }
        }

        // Set initial version
        if ($metadata->versionProperty !== null) {
            $versionCol = $metadata->columns[$metadata->versionProperty];
            $values[$versionCol->columnName] = 1;
        }

        $insertBuilder = new InsertBuilder(
            $this->connection,
            $metadata->qualifiedTableName(),
            $metadata->primaryKey->columnName,
        );
        $lastInsertId = $insertBuilder->values($values)->execute();

        // Set the generated ID and initial version back on the entity
        if ($metadata->primaryKey->autoIncrement || $metadata->versionProperty !== null) {
            $reflection = new ReflectionClass($entity);

            if ($metadata->primaryKey->autoIncrement) {
                $prop = $reflection->getProperty($metadata->primaryKey->propertyName);
                $prop->setValue($entity, $metadata->primaryKey->type === ColumnType::Integer
                    || $metadata->primaryKey->type === ColumnType::BigInt
                    ? (int) $lastInsertId
                    : $lastInsertId);
            }

            if ($metadata->versionProperty !== null) {
                $prop = $reflection->getProperty($metadata->versionProperty);
                $prop->setValue($entity, 1);
            }
        }

        $this->logAudit($metadata, $context, 'insert', $this->dehydrator->extractId($entity));
    }

    /**
     * Constrain a write to the active tenant. Returns true when a tenant
     * predicate was applied, so a caller can treat affected-rows==0 as a
     * cross-tenant isolation failure rather than a silent no-op.
     */
    private function applyTenantScope(UpdateBuilder|DeleteBuilder $builder, EntityMetadata $metadata): bool
    {
        $predicate = $this->tenantEnricher?->activeTenantColumn($metadata);

        if ($predicate === null) {
            return false;
        }

        $builder->where($predicate[0], $predicate[1]);

        return true;
    }

    /**
     * Update an existing entity.
     *
     * @throws OptimisticLockException
     * @throws TenantIsolationException
     */
    public function update(object $entity, MutationContext $context): void
    {
        $metadata = $this->metadataRegistry->get($entity::class);
        $values = $this->dehydrator->dehydrateForUpdate($entity);
        $id = $this->dehydrator->extractId($entity);

        // Update timestamp
        if ($metadata->hasTimestamps && $metadata->updatedAtColumn !== null) {
            $values[$metadata->updatedAtColumn] = date('Y-m-d H:i:s');
        }

        // Optimistic locking
        if ($metadata->versionProperty !== null) {
            $currentVersion = $this->dehydrator->extractVersion($entity);
            if ($currentVersion === null) {
                $currentVersion = 0;
            }

            $versionCol = $metadata->columns[$metadata->versionProperty];
            // Add version to SET
            $newVersion = $currentVersion + 1;
            $allValues = $values;
            $allValues[$versionCol->columnName] = $newVersion;

            // Schema-qualified like every other write here: a bare table name
            // resolves against the session search_path on PostgreSQL, targeting
            // the wrong (or no) table — the UPDATE then affects 0 rows and is
            // misreported as a stale-entity optimistic-lock conflict.
            $versionedBuilder = new UpdateBuilder($this->connection, $metadata->qualifiedTableName());
            // Tenant predicate: a cross-tenant update matches no row and is
            // blocked (reported here as a stale-entity conflict — the write is
            // rejected either way, which is what matters for isolation).
            $this->applyTenantScope($versionedBuilder, $metadata);
            $affected = $versionedBuilder
                ->set($allValues)
                ->where($metadata->primaryKey->columnName, $id)
                ->where($versionCol->columnName, $currentVersion)
                ->execute();

            if ($affected === 0) {
                throw OptimisticLockException::staleEntity($entity::class, $id);
            }

            // Update version on entity
            $reflection = new ReflectionClass($entity);
            $prop = $reflection->getProperty($metadata->versionProperty);
            $prop->setValue($entity, $newVersion);
        } else {
            $updateBuilder = new UpdateBuilder($this->connection, $metadata->qualifiedTableName());
            $tenantScoped = $this->applyTenantScope($updateBuilder, $metadata);
            $affected = $updateBuilder
                ->set($values)
                ->where($metadata->primaryKey->columnName, $id)
                ->execute();

            if ($tenantScoped && $affected === 0) {
                throw TenantIsolationException::writeMatchedNoTenantRow($entity::class, 'update', $id);
            }
        }

        $this->logAudit($metadata, $context, 'update', $id);
    }

    /**
     * Delete an entity (or soft-delete if configured).
     *
     * @throws TenantIsolationException
     */
    public function delete(object $entity, MutationContext $context): void
    {
        $metadata = $this->metadataRegistry->get($entity::class);
        $id = $this->dehydrator->extractId($entity);

        if ($metadata->hasSoftDelete && $metadata->softDeleteColumn !== null) {
            // Soft delete: set the deleted_at column
            $updateBuilder = new UpdateBuilder($this->connection, $metadata->qualifiedTableName());
            $tenantScoped = $this->applyTenantScope($updateBuilder, $metadata);
            $affected = $updateBuilder
                ->set([$metadata->softDeleteColumn => date('Y-m-d H:i:s')])
                ->where($metadata->primaryKey->columnName, $id)
                ->execute();

            if ($tenantScoped && $affected === 0) {
                throw TenantIsolationException::writeMatchedNoTenantRow($entity::class, 'delete', $id);
            }
        } else {
            // Hard delete
            $deleteBuilder = new DeleteBuilder($this->connection, $metadata->qualifiedTableName());
            $tenantScoped = $this->applyTenantScope($deleteBuilder, $metadata);
            $affected = $deleteBuilder->where($metadata->primaryKey->columnName, $id)->execute();

            if ($tenantScoped && $affected === 0) {
                throw TenantIsolationException::writeMatchedNoTenantRow($entity::class, 'delete', $id);
            }
        }

        $this->logAudit($metadata, $context, 'delete', $id);
    }

    private function logAudit(
        EntityMetadata $metadata,
        MutationContext $context,
        string $action,
        string|int $entityId,
    ): void {
        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $context->actor,
            sprintf('orm.%s', $action),
            sprintf('%s#%s', $metadata->entityClass, $entityId),
            [
                'table' => $metadata->tableName,
                'reason' => $context->reason,
                'correlation_id' => $context->correlationId,
            ],
        );
    }
}
