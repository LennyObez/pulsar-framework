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
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Query\DeleteBuilder;
use Pulsar\Extension\Orm\Features\Query\InsertBuilder;
use Pulsar\Extension\Orm\Features\Query\UpdateBuilder;
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
    ) {}

    /**
     * Insert a new entity.
     */
    public function insert(object $entity, MutationContext $context): void
    {
        $metadata = $this->metadataRegistry->get($entity::class);
        $values = $this->dehydrator->dehydrateForInsert($entity);

        // Add timestamps
        if ($metadata->hasTimestamps && $metadata->createdAtColumn !== null) {
            $values[$metadata->createdAtColumn] = date('Y-m-d H:i:s');
            if ($metadata->updatedAtColumn !== null) {
                $values[$metadata->updatedAtColumn] = date('Y-m-d H:i:s');
            }
        }

        // Tenant column: value should already be on the entity,
        // handled by TenantInsertEnricher if needed.

        // Set initial version
        if ($metadata->versionProperty !== null) {
            $versionCol = $metadata->columns[$metadata->versionProperty];
            $values[$versionCol->columnName] = 1;
        }

        $insertBuilder = new InsertBuilder($this->connection, $metadata->tableName);
        $lastInsertId = $insertBuilder->values($values)->execute();

        // Set the generated ID back on the entity
        if ($metadata->primaryKey->autoIncrement) {
            $reflection = new ReflectionClass($entity);
            $prop = $reflection->getProperty($metadata->primaryKey->propertyName);
            $prop->setValue($entity, $metadata->primaryKey->type === ColumnType::Integer
                || $metadata->primaryKey->type === ColumnType::BigInt
                ? (int) $lastInsertId
                : $lastInsertId);
        }

        // Set initial version back on entity
        if ($metadata->versionProperty !== null) {
            $reflection = new ReflectionClass($entity);
            $prop = $reflection->getProperty($metadata->versionProperty);
            $prop->setValue($entity, 1);
        }

        $this->logAudit($metadata, $context, 'insert', $this->dehydrator->extractId($entity));
    }

    /**
     * Update an existing entity.
     *
     * @throws OptimisticLockException
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

        $updateBuilder = new UpdateBuilder($this->connection, $metadata->tableName);
        $updateBuilder->set($values)->where($metadata->primaryKey->columnName, $id);

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

            $versionedBuilder = new UpdateBuilder($this->connection, $metadata->tableName);
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
            $updateBuilder->execute();
        }

        $this->logAudit($metadata, $context, 'update', $id);
    }

    /**
     * Delete an entity (or soft-delete if configured).
     */
    public function delete(object $entity, MutationContext $context): void
    {
        $metadata = $this->metadataRegistry->get($entity::class);
        $id = $this->dehydrator->extractId($entity);

        if ($metadata->hasSoftDelete && $metadata->softDeleteColumn !== null) {
            // Soft delete: set the deleted_at column
            $updateBuilder = new UpdateBuilder($this->connection, $metadata->tableName);
            $updateBuilder
                ->set([$metadata->softDeleteColumn => date('Y-m-d H:i:s')])
                ->where($metadata->primaryKey->columnName, $id)
                ->execute();
        } else {
            // Hard delete
            $deleteBuilder = new DeleteBuilder($this->connection, $metadata->tableName);
            $deleteBuilder->where($metadata->primaryKey->columnName, $id)->execute();
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
