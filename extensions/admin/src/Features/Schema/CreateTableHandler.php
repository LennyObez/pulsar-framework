<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\Schema;

use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\Schema\SchemaIdentifier;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\SchemaSqlCanonicalizer;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogEntry;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Throwable;

use function array_map;
use function bin2hex;
use function hash;
use function implode;
use function in_array;
use function random_bytes;
use function str_starts_with;
use function strtolower;
use function time;

/**
 * Handles creating a new database table with full audit trail.
 */
final readonly class CreateTableHandler
{
    public function __construct(
        private SchemaManager $schemaManager,
        private DatabaseIntrospector $introspector,
        private AuditLoggerInterface $auditLogger,
        private SchemaChangeLogStoreInterface $changeLog,
        private AdminSchemaConfig $config,
    ) {}

    /**
     * @return array{success: bool, message: string, sql?: list<string>}
     */
    public function execute(TableDefinition $definition, MutationContext $context): array
    {
        // Validate table name
        SchemaIdentifier::validateTable($definition->name);

        // Check deny prefixes
        foreach ($this->config->denyTablePrefixes as $prefix) {
            if (str_starts_with(strtolower($definition->name), strtolower($prefix))) {
                return ['success' => false, 'message' => "Table prefix \"$prefix\" is reserved and cannot be used"];
            }
        }

        // Validate column names
        foreach ($definition->columns as $column) {
            SchemaIdentifier::validateColumn($column->name);
        }

        // Validate index names
        foreach ($definition->indexes as $index) {
            SchemaIdentifier::validateIndex($index->name);
            foreach ($index->columns as $col) {
                SchemaIdentifier::validateColumn($col);
            }
        }

        // Validate foreign key names
        foreach ($definition->foreignKeys as $fk) {
            SchemaIdentifier::validateForeignKey($fk->name);
        }

        // Check table doesn't already exist
        $existingTables = array_map(
            static fn($t): string => strtolower($t->name),
            $this->introspector->tables(),
        );

        if (in_array(strtolower($definition->name), $existingTables, true)) {
            return ['success' => false, 'message' => "Table \"$definition->name\" already exists"];
        }

        // Preview SQL for audit
        $statements = $this->schemaManager->previewCreateTable($definition);
        $canonicalized = SchemaSqlCanonicalizer::canonicalize($statements);
        $evidenceHash = hash('sha256', $canonicalized);

        try {
            $this->schemaManager->createTable($definition);

            $this->auditLogger->log(
                event: AuditEvent::SchemaModification,
                outcome: AuditOutcome::Success,
                actor: $context->actor,
                action: 'schema.create_table',
                resource: $definition->name,
                metadata: [
                    'operation' => 'create_table',
                    'table' => $definition->name,
                    'sql_fingerprint' => hash('sha256', implode("\n", $statements)),
                    'evidence_hash' => $evidenceHash,
                    'reason' => $context->reason,
                    'correlation_id' => $context->correlationId,
                    'affected_identifiers' => [
                        'columns' => array_map(static fn($c): string => $c->name, $definition->columns),
                        'indexes' => array_map(static fn($i): string => $i->name, $definition->indexes),
                    ],
                ],
            );

            $this->changeLog->record(new SchemaChangeLogEntry(
                id: bin2hex(random_bytes(16)),
                operation: 'create_table',
                table: $definition->name,
                actor: $context->actor,
                reason: $context->reason,
                timestamp: time(),
                statements: $statements,
                evidenceHash: $evidenceHash,
                correlationId: $context->correlationId,
                success: true,
            ));

            return ['success' => true, 'message' => "Table \"$definition->name\" created successfully", 'sql' => $statements];
        } catch (Throwable $e) {
            $this->auditLogger->log(
                event: AuditEvent::SchemaModification,
                outcome: AuditOutcome::Error,
                actor: $context->actor,
                action: 'schema.create_table',
                resource: $definition->name,
                metadata: [
                    'operation' => 'create_table',
                    'table' => $definition->name,
                    'error' => $e->getMessage(),
                    'reason' => $context->reason,
                ],
            );

            $this->changeLog->record(new SchemaChangeLogEntry(
                id: bin2hex(random_bytes(16)),
                operation: 'create_table',
                table: $definition->name,
                actor: $context->actor,
                reason: $context->reason,
                timestamp: time(),
                statements: $statements,
                evidenceHash: $evidenceHash,
                correlationId: $context->correlationId,
                success: false,
            ));

            return ['success' => false, 'message' => 'Failed to create table: ' . $e->getMessage()];
        }
    }
}
