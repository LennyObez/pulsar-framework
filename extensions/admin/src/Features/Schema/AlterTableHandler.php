<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\Schema;

use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaIdentifier;
use Pulsar\Database\Schema\SchemaIndex;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\SchemaSqlCanonicalizer;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogEntry;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Throwable;

use function bin2hex;
use function hash;
use function implode;
use function random_bytes;
use function str_starts_with;
use function strtolower;
use function time;

/**
 * Handles ALTER TABLE operations: add column, drop column, add index, drop index.
 */
final readonly class AlterTableHandler
{
    public function __construct(
        private SchemaManager $schemaManager,
        private SchemaCapabilities $capabilities,
        private AuditLoggerInterface $auditLogger,
        private SchemaChangeLogStoreInterface $changeLog,
        private AdminSchemaConfig $config,
    ) {}

    /**
     * @return array{success: bool, message: string, sql?: list<string>}
     */
    public function addColumn(string $table, SchemaColumn $column, MutationContext $context): array
    {
        $this->validateTableName($table);
        SchemaIdentifier::validateColumn($column->name);

        $statements = $this->schemaManager->previewAddColumn($table, $column);

        return $this->executeAndAudit(
            operation: 'add_column',
            table: $table,
            statements: $statements,
            context: $context,
            execute: fn() => $this->schemaManager->addColumn($table, $column),
            identifiers: ['columns' => [$column->name]],
        );
    }

    /**
     * @return array{success: bool, message: string, sql?: list<string>, warning?: string}
     */
    public function dropColumn(string $table, string $column, MutationContext $context): array
    {
        $this->validateTableName($table);
        SchemaIdentifier::validateColumn($column);

        if (!$this->capabilities->supportsDropColumn()) {
            return [
                'success' => false,
                'message' => 'DROP COLUMN is not supported by this database driver',
                'warning' => 'SQLITE_NO_DROP_COLUMN',
            ];
        }

        $statements = $this->schemaManager->previewDropColumn($table, $column);

        return $this->executeAndAudit(
            operation: 'drop_column',
            table: $table,
            statements: $statements,
            context: $context,
            execute: fn() => $this->schemaManager->dropColumn($table, $column),
            identifiers: ['columns' => [$column]],
        );
    }

    /**
     * @return array{success: bool, message: string, sql?: list<string>}
     */
    public function addIndex(string $table, SchemaIndex $index, MutationContext $context): array
    {
        $this->validateTableName($table);
        SchemaIdentifier::validateIndex($index->name);

        foreach ($index->columns as $col) {
            SchemaIdentifier::validateColumn($col);
        }

        $statements = $this->schemaManager->previewAddIndex($table, $index);

        return $this->executeAndAudit(
            operation: 'add_index',
            table: $table,
            statements: $statements,
            context: $context,
            execute: fn() => $this->schemaManager->addIndex($table, $index),
            identifiers: ['indexes' => [$index->name]],
        );
    }

    /**
     * @return array{success: bool, message: string, sql?: list<string>}
     */
    public function dropIndex(string $table, string $indexName, MutationContext $context): array
    {
        $this->validateTableName($table);
        SchemaIdentifier::validateIndex($indexName);

        $statements = $this->schemaManager->previewDropIndex($table, $indexName);

        return $this->executeAndAudit(
            operation: 'drop_index',
            table: $table,
            statements: $statements,
            context: $context,
            execute: fn() => $this->schemaManager->dropIndex($table, $indexName),
            identifiers: ['indexes' => [$indexName]],
        );
    }

    private function validateTableName(string $table): void
    {
        SchemaIdentifier::validateTable($table);

        foreach ($this->config->denyTablePrefixes as $prefix) {
            if (str_starts_with(strtolower($table), strtolower($prefix))) {
                throw new AdminException(
                    "Table prefix \"{$prefix}\" is reserved and cannot be modified",
                );
            }
        }
    }

    /**
     * @param list<string> $statements
     * @param callable(): void $execute
     * @param array<string, list<string>> $identifiers
     * @return array{success: bool, message: string, sql?: list<string>}
     */
    private function executeAndAudit(
        string $operation,
        string $table,
        array $statements,
        MutationContext $context,
        callable $execute,
        array $identifiers,
    ): array {
        $canonicalized = SchemaSqlCanonicalizer::canonicalize($statements);
        $evidenceHash = hash('sha256', $canonicalized);

        try {
            $execute();

            $this->auditLogger->log(
                event: AuditEvent::SchemaModification,
                outcome: AuditOutcome::Success,
                actor: $context->actor,
                action: 'schema.' . $operation,
                resource: $table,
                metadata: [
                    'operation' => $operation,
                    'table' => $table,
                    'sql_fingerprint' => hash('sha256', implode("\n", $statements)),
                    'evidence_hash' => $evidenceHash,
                    'reason' => $context->reason,
                    'correlation_id' => $context->correlationId,
                    'affected_identifiers' => $identifiers,
                ],
            );

            $this->changeLog->record(new SchemaChangeLogEntry(
                id: bin2hex(random_bytes(16)),
                operation: $operation,
                table: $table,
                actor: $context->actor,
                reason: $context->reason,
                timestamp: time(),
                statements: $statements,
                evidenceHash: $evidenceHash,
                correlationId: $context->correlationId,
                success: true,
            ));

            return ['success' => true, 'message' => "Operation \"{$operation}\" on \"{$table}\" completed", 'sql' => $statements];
        } catch (Throwable $e) {
            $this->auditLogger->log(
                event: AuditEvent::SchemaModification,
                outcome: AuditOutcome::Error,
                actor: $context->actor,
                action: 'schema.' . $operation,
                resource: $table,
                metadata: [
                    'operation' => $operation,
                    'table' => $table,
                    'error' => $e->getMessage(),
                    'reason' => $context->reason,
                ],
            );

            $this->changeLog->record(new SchemaChangeLogEntry(
                id: bin2hex(random_bytes(16)),
                operation: $operation,
                table: $table,
                actor: $context->actor,
                reason: $context->reason,
                timestamp: time(),
                statements: $statements,
                evidenceHash: $evidenceHash,
                correlationId: $context->correlationId,
                success: false,
            ));

            return ['success' => false, 'message' => "Failed: {$e->getMessage()}"];
        }
    }
}
