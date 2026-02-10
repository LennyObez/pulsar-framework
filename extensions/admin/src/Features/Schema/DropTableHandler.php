<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\Schema;

use function array_map;
use function bin2hex;
use function hash;
use function in_array;

use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\MutationContext;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\Schema\SchemaIdentifier;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\SchemaSqlCanonicalizer;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogEntry;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function random_bytes;
use function str_starts_with;
use function strtolower;

use Throwable;

use function time;

/**
 * Handles dropping a database table with audit trail.
 */
final readonly class DropTableHandler
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
    public function execute(string $table, MutationContext $context): array
    {
        SchemaIdentifier::validateTable($table);

        foreach ($this->config->denyTablePrefixes as $prefix) {
            if (str_starts_with(strtolower($table), strtolower($prefix))) {
                return ['success' => false, 'message' => "Table prefix \"$prefix\" is reserved and cannot be dropped"];
            }
        }

        $existingTables = array_map(
            static fn($t): string => strtolower($t->name),
            $this->introspector->tables(),
        );

        if (!in_array(strtolower($table), $existingTables, true)) {
            return ['success' => false, 'message' => "Table \"$table\" does not exist"];
        }

        $statements = $this->schemaManager->previewDropTable($table);
        $canonicalized = SchemaSqlCanonicalizer::canonicalize($statements);
        $evidenceHash = hash('sha256', $canonicalized);

        try {
            $this->schemaManager->dropTable($table);

            $this->auditLogger->log(
                event: AuditEvent::SchemaModification,
                outcome: AuditOutcome::Success,
                actor: $context->actor,
                action: 'schema.drop_table',
                resource: $table,
                metadata: [
                    'operation' => 'drop_table',
                    'table' => $table,
                    'evidence_hash' => $evidenceHash,
                    'reason' => $context->reason,
                    'correlation_id' => $context->correlationId,
                ],
            );

            $this->changeLog->record(new SchemaChangeLogEntry(
                id: bin2hex(random_bytes(16)),
                operation: 'drop_table',
                table: $table,
                actor: $context->actor,
                reason: $context->reason,
                timestamp: time(),
                statements: $statements,
                evidenceHash: $evidenceHash,
                correlationId: $context->correlationId,
                success: true,
            ));

            return ['success' => true, 'message' => "Table \"$table\" dropped successfully", 'sql' => $statements];
        } catch (Throwable $e) {
            $this->auditLogger->log(
                event: AuditEvent::SchemaModification,
                outcome: AuditOutcome::Error,
                actor: $context->actor,
                action: 'schema.drop_table',
                resource: $table,
                metadata: ['operation' => 'drop_table', 'table' => $table, 'error' => $e->getMessage(), 'reason' => $context->reason],
            );

            $this->changeLog->record(new SchemaChangeLogEntry(
                id: bin2hex(random_bytes(16)),
                operation: 'drop_table',
                table: $table,
                actor: $context->actor,
                reason: $context->reason,
                timestamp: time(),
                statements: $statements,
                evidenceHash: $evidenceHash,
                correlationId: $context->correlationId,
                success: false,
            ));

            return ['success' => false, 'message' => 'Failed to drop table: ' . $e->getMessage()];
        }
    }
}
