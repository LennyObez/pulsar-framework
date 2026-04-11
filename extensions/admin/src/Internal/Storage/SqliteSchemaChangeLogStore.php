<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Storage;

use Override;
use PDO;
use Pulsar\Api\Internal;

use function array_map;
use function date;
use function hash;
use function implode;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * SQLite-backed schema change log store.
 */
#[Internal]
final class SqliteSchemaChangeLogStore implements SchemaChangeLogStoreInterface
{
    private bool $initialized = false;

    public function __construct(
        private readonly PDO $pdo,
    ) {}

    #[Override]
    public function record(SchemaChangeLogEntry $entry): void
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_schema_changelog (id, operation, table_name, actor, reason, timestamp, statements, evidence_hash, correlation_id, success)
             VALUES (:id, :operation, :table_name, :actor, :reason, :timestamp, :statements, :evidence_hash, :correlation_id, :success)',
        );
        $stmt->execute([
            'id' => $entry->id,
            'operation' => $entry->operation,
            'table_name' => $entry->table,
            'actor' => $entry->actor,
            'reason' => $entry->reason,
            'timestamp' => $entry->timestamp,
            'statements' => json_encode($entry->statements, JSON_THROW_ON_ERROR),
            'evidence_hash' => $entry->evidenceHash,
            'correlation_id' => $entry->correlationId,
            'success' => $entry->success ? 1 : 0,
        ]);
    }

    /**
     * @return list<SchemaChangeLogEntry>
     */
    #[Override]
    public function recent(int $limit = 100): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM admin_schema_changelog ORDER BY timestamp DESC LIMIT :limit',
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateAll($rows);
    }

    /**
     * @return list<SchemaChangeLogEntry>
     */
    #[Override]
    public function forTable(string $table, int $limit = 50): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM admin_schema_changelog WHERE table_name = :table ORDER BY timestamp DESC LIMIT :limit',
        );
        $stmt->bindValue('table', $table);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateAll($rows);
    }

    #[Override]
    public function exportSqlBundle(): string
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM admin_schema_changelog ORDER BY timestamp ASC',
        );
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $entries = $this->hydrateAll($rows);

        $lines = [];
        $lines[] = '-- Schema Change Log Export';
        $lines[] = '-- Generated: ' . date('c');

        $allStatements = [];
        foreach ($entries as $entry) {
            foreach ($entry->statements as $s) {
                $allStatements[] = $s;
            }
        }

        $bundleHash = hash('sha256', implode("\n", $allStatements));
        $lines[] = '-- Evidence Hash: sha256:' . $bundleHash;
        $lines[] = '';

        foreach ($entries as $entry) {
            $time = date('Y-m-d H:i:s', $entry->timestamp);
            $status = $entry->success ? '' : ' [FAILED]';
            $lines[] = "-- [$time]$status $entry->operation \"$entry->table\" by $entry->actor";
            $lines[] = "-- Reason: $entry->reason";
            $lines[] = "-- Evidence: sha256:$entry->evidenceHash";

            foreach ($entry->statements as $sql) {
                $lines[] = $sql . ';';
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function ensureSchema(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_schema_changelog (
                id TEXT PRIMARY KEY,
                operation TEXT NOT NULL,
                table_name TEXT NOT NULL,
                actor TEXT NOT NULL,
                reason TEXT NOT NULL,
                timestamp INTEGER NOT NULL,
                statements TEXT NOT NULL,
                evidence_hash TEXT NOT NULL,
                correlation_id TEXT,
                success INTEGER NOT NULL DEFAULT 1
            )',
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_schema_changelog_table ON admin_schema_changelog (table_name, timestamp DESC)',
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_schema_changelog_timestamp ON admin_schema_changelog (timestamp DESC)',
        );

        $this->initialized = true;
    }

    /**
     * @param list<array{
     *     id: string,
     *     operation: string,
     *     table_name: string,
     *     actor: string,
     *     reason: string,
     *     timestamp: int,
     *     statements: string,
     *     evidence_hash: string,
     *     correlation_id: string|null,
     *     success: int,
     * }> $rows
     * @return list<SchemaChangeLogEntry>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(
            static function (array $row): SchemaChangeLogEntry {
                /** @var list<string> $statements */
                $statements = json_decode($row['statements'], true, 512, JSON_THROW_ON_ERROR);

                return new SchemaChangeLogEntry(
                    id: $row['id'],
                    operation: $row['operation'],
                    table: $row['table_name'],
                    actor: $row['actor'],
                    reason: $row['reason'],
                    timestamp: $row['timestamp'],
                    statements: $statements,
                    evidenceHash: $row['evidence_hash'],
                    correlationId: $row['correlation_id'],
                    success: (bool) $row['success'],
                );
            },
            $rows,
        );
    }
}
