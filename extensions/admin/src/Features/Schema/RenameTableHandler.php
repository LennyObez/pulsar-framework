<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\Schema;

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
use Throwable;

use function array_map;
use function bin2hex;
use function hash;
use function in_array;
use function random_bytes;
use function str_starts_with;
use function strtolower;
use function time;

/**
 * Handles renaming a database table with audit trail.
 */
final readonly class RenameTableHandler
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
    public function execute(string $from, string $to, MutationContext $context): array
    {
        SchemaIdentifier::validateTable($from);
        SchemaIdentifier::validateTable($to);

        foreach ($this->config->denyTablePrefixes as $prefix) {
            if (str_starts_with(strtolower($from), strtolower($prefix))) {
                return ['success' => false, 'message' => "Table prefix \"$prefix\" is reserved"];
            }

            if (str_starts_with(strtolower($to), strtolower($prefix))) {
                return ['success' => false, 'message' => "Cannot rename to a reserved prefix \"$prefix\""];
            }
        }

        $existingTables = array_map(
            static fn($t): string => strtolower($t->name),
            $this->introspector->tables(),
        );

        if (!in_array(strtolower($from), $existingTables, true)) {
            return ['success' => false, 'message' => "Table \"$from\" does not exist"];
        }

        if (in_array(strtolower($to), $existingTables, true)) {
            return ['success' => false, 'message' => "Table \"$to\" already exists"];
        }

        $statements = $this->schemaManager->previewRenameTable($from, $to);
        $canonicalized = SchemaSqlCanonicalizer::canonicalize($statements);
        $evidenceHash = hash('sha256', $canonicalized);

        try {
            $this->schemaManager->renameTable($from, $to);

            $this->auditLogger->log(
                event: AuditEvent::SchemaModification,
                outcome: AuditOutcome::Success,
                actor: $context->actor,
                action: 'schema.rename_table',
                resource: $from,
                metadata: [
                    'operation' => 'rename_table',
                    'table' => $from,
                    'new_name' => $to,
                    'evidence_hash' => $evidenceHash,
                    'reason' => $context->reason,
                    'correlation_id' => $context->correlationId,
                ],
            );

            $this->changeLog->record(new SchemaChangeLogEntry(
                id: bin2hex(random_bytes(16)),
                operation: 'rename_table',
                table: $from . ' -> ' . $to,
                actor: $context->actor,
                reason: $context->reason,
                timestamp: time(),
                statements: $statements,
                evidenceHash: $evidenceHash,
                correlationId: $context->correlationId,
                success: true,
            ));

            return ['success' => true, 'message' => "Table \"$from\" renamed to \"$to\"", 'sql' => $statements];
        } catch (Throwable $e) {
            $this->auditLogger->log(
                event: AuditEvent::SchemaModification,
                outcome: AuditOutcome::Error,
                actor: $context->actor,
                action: 'schema.rename_table',
                resource: $from,
                metadata: ['operation' => 'rename_table', 'table' => $from, 'new_name' => $to, 'error' => $e->getMessage(), 'reason' => $context->reason],
            );

            $this->changeLog->record(new SchemaChangeLogEntry(
                id: bin2hex(random_bytes(16)),
                operation: 'rename_table',
                table: $from . ' -> ' . $to,
                actor: $context->actor,
                reason: $context->reason,
                timestamp: time(),
                statements: $statements,
                evidenceHash: $evidenceHash,
                correlationId: $context->correlationId,
                success: false,
            ));

            return ['success' => false, 'message' => 'Failed to rename table: ' . $e->getMessage()];
        }
    }
}
