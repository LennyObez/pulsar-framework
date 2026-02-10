<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Adapter;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Throwable;

use function array_keys;
use function implode;

/**
 * SQL-based mutation implementation for ORM-backed admin resources.
 *
 * All writes require a MutationContext and are audit-logged.
 */
#[Internal]
final class OrmResourceMutator implements ResourceMutatorInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    #[Override]
    public function create(
        DataResourceInterface $resource,
        array $data,
        MutationContext $context,
    ): ActionResult {
        $table = $this->tableName($resource);
        $editableFields = $this->editableFieldNames($resource);
        $filtered = array_intersect_key($data, array_flip($editableFields));

        if ($filtered === []) {
            return ActionResult::failure('No valid fields provided for creation');
        }

        $columns = array_keys($filtered);
        $placeholders = array_map(static fn(string $col): string => ":{$col}", $columns);

        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        try {
            $this->connection->execute($sql, $filtered);
            $id = $this->connection->lastInsertId();

            $this->auditLogger->log(
                event: AuditEvent::DataModification,
                outcome: AuditOutcome::Success,
                actor: $context->actor,
                action: "admin.create.{$resource->name()}",
                resource: "{$resource->name()}:{$id}",
                metadata: ['reason' => $context->reason, 'correlation_id' => $context->correlationId],
            );

            return ActionResult::success(
                "Created {$resource->label()} #{$id}",
                ['id' => $id],
            );
        } catch (Throwable $e) {
            $this->auditLogger->log(
                event: AuditEvent::DataModification,
                outcome: AuditOutcome::Error,
                actor: $context->actor,
                action: "admin.create.{$resource->name()}",
                resource: $resource->name(),
                metadata: ['error' => $e->getMessage()],
            );

            return ActionResult::failure("Failed to create {$resource->label()}: {$e->getMessage()}");
        }
    }

    #[Override]
    public function update(
        DataResourceInterface $resource,
        string $id,
        array $data,
        MutationContext $context,
    ): ActionResult {
        $table = $this->tableName($resource);
        $pk = $resource->primaryKey();
        $editableFields = $this->editableFieldNames($resource);
        $filtered = array_intersect_key($data, array_flip($editableFields));

        if ($filtered === []) {
            return ActionResult::failure('No valid fields provided for update');
        }

        $setClauses = array_map(
            static fn(string $col): string => "{$col} = :{$col}",
            array_keys($filtered),
        );

        $sql = "UPDATE {$table} SET " . implode(', ', $setClauses) . " WHERE {$pk} = :_pk_id";
        $bindings = [...$filtered, '_pk_id' => $id];

        try {
            $affected = $this->connection->execute($sql, $bindings);

            if ($affected === 0) {
                return ActionResult::failure("{$resource->label()} #{$id} not found");
            }

            $this->auditLogger->log(
                event: AuditEvent::DataModification,
                outcome: AuditOutcome::Success,
                actor: $context->actor,
                action: "admin.update.{$resource->name()}",
                resource: "{$resource->name()}:{$id}",
                metadata: [
                    'reason' => $context->reason,
                    'fields' => array_keys($filtered),
                    'correlation_id' => $context->correlationId,
                ],
            );

            return ActionResult::success("Updated {$resource->label()} #{$id}");
        } catch (Throwable $e) {
            $this->auditLogger->log(
                event: AuditEvent::DataModification,
                outcome: AuditOutcome::Error,
                actor: $context->actor,
                action: "admin.update.{$resource->name()}",
                resource: "{$resource->name()}:{$id}",
                metadata: ['error' => $e->getMessage()],
            );

            return ActionResult::failure("Failed to update {$resource->label()}: {$e->getMessage()}");
        }
    }

    #[Override]
    public function delete(
        DataResourceInterface $resource,
        string $id,
        MutationContext $context,
    ): ActionResult {
        $table = $this->tableName($resource);
        $pk = $resource->primaryKey();

        $sql = "DELETE FROM {$table} WHERE {$pk} = :id";

        try {
            $affected = $this->connection->execute($sql, ['id' => $id]);

            if ($affected === 0) {
                return ActionResult::failure("{$resource->label()} #{$id} not found");
            }

            $this->auditLogger->log(
                event: AuditEvent::DataModification,
                outcome: AuditOutcome::Success,
                actor: $context->actor,
                action: "admin.delete.{$resource->name()}",
                resource: "{$resource->name()}:{$id}",
                metadata: ['reason' => $context->reason, 'correlation_id' => $context->correlationId],
            );

            return ActionResult::success("Deleted {$resource->label()} #{$id}");
        } catch (Throwable $e) {
            $this->auditLogger->log(
                event: AuditEvent::DataModification,
                outcome: AuditOutcome::Error,
                actor: $context->actor,
                action: "admin.delete.{$resource->name()}",
                resource: "{$resource->name()}:{$id}",
                metadata: ['error' => $e->getMessage()],
            );

            return ActionResult::failure("Failed to delete {$resource->label()}: {$e->getMessage()}");
        }
    }

    #[Override]
    public function bulkAction(
        DataResourceInterface $resource,
        string $action,
        array $ids,
        array $parameters,
        MutationContext $context,
    ): ActionResult {
        $table = $this->tableName($resource);
        $pk = $resource->primaryKey();

        if ($ids === []) {
            return ActionResult::failure('No records selected');
        }

        $supportedActions = array_map(
            static fn($ba): string => $ba->name,
            $resource->bulkActions(),
        );

        if (!in_array($action, $supportedActions, true)) {
            return ActionResult::failure("Unsupported bulk action: {$action}");
        }

        try {
            $affected = 0;

            if ($action === 'delete') {
                $placeholders = [];
                $bindings = [];
                foreach ($ids as $i => $id) {
                    $param = "id_{$i}";
                    $placeholders[] = ":{$param}";
                    $bindings[$param] = $id;
                }

                $sql = "DELETE FROM {$table} WHERE {$pk} IN (" . implode(', ', $placeholders) . ')';
                $affected = $this->connection->execute($sql, $bindings);
            }

            $this->auditLogger->log(
                event: AuditEvent::DataModification,
                outcome: AuditOutcome::Success,
                actor: $context->actor,
                action: "admin.bulk.{$action}.{$resource->name()}",
                resource: $resource->name(),
                metadata: [
                    'ids' => $ids,
                    'affected' => $affected,
                    'reason' => $context->reason,
                    'correlation_id' => $context->correlationId,
                ],
            );

            return ActionResult::success(
                "Bulk {$action} completed: {$affected} record(s) affected",
                ['affected' => $affected],
            );
        } catch (Throwable $e) {
            $this->auditLogger->log(
                event: AuditEvent::DataModification,
                outcome: AuditOutcome::Error,
                actor: $context->actor,
                action: "admin.bulk.{$action}.{$resource->name()}",
                resource: $resource->name(),
                metadata: ['error' => $e->getMessage(), 'ids' => $ids],
            );

            return ActionResult::failure("Bulk {$action} failed: {$e->getMessage()}");
        }
    }

    private function tableName(DataResourceInterface $resource): string
    {
        if ($resource instanceof OrmResourceAdapter) {
            return $resource->tableName();
        }
        return $resource->name();
    }

    /**
     * @return list<string>
     */
    private function editableFieldNames(DataResourceInterface $resource): array
    {
        return array_values(array_map(
            static fn(FieldDefinition $f): string => $f->name,
            array_filter(
                $resource->fields(),
                static fn(FieldDefinition $f): bool => $f->editable,
            ),
        ));
    }
}
