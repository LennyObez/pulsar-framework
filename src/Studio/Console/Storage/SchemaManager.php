<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Storage;

use PDO;
use PDOException;
use Pulsar\Api\Internal;
use Pulsar\Studio\Exception\StudioException;

/**
 * Manages the SQLite schema for Studio event storage.
 *
 * Creates tables, indexes, and sets operational pragmas.
 */
#[Internal]
final class SchemaManager
{
    private const string SCHEMA_VERSION = '1';

    private function __construct() {}

    /**
     * Ensure the Studio schema exists, creating tables and indexes as needed.
     */
    public static function ensureSchema(PDO $pdo): void
    {
        try {
            self::setPragmas($pdo);
            self::createTables($pdo);
            self::createIndexes($pdo);
            self::setSchemaVersion($pdo);
        } catch (PDOException $e) {
            throw StudioException::schemaFailed($e->getMessage(), $e);
        }
    }

    private static function setPragmas(PDO $pdo): void
    {
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA cache_size = -2000');
    }

    private static function createTables(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS studio_meta (
                    key TEXT PRIMARY KEY NOT NULL,
                    value TEXT NOT NULL
                )
            SQL);

        $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS studio_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    event_id TEXT NOT NULL UNIQUE,
                    event_type TEXT NOT NULL,
                    schema_version INTEGER NOT NULL DEFAULT 1,
                    timestamp_us INTEGER NOT NULL,
                    request_id TEXT,
                    trace_id TEXT,
                    span_id TEXT,
                    job_id TEXT,
                    app_env TEXT NOT NULL,
                    hostname TEXT NOT NULL,
                    tenant_hash TEXT,
                    payload_json TEXT NOT NULL,
                    payload_hash TEXT NOT NULL,
                    ciphertext_hash TEXT
                )
            SQL);

        $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS studio_chain (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    event_id TEXT NOT NULL UNIQUE REFERENCES studio_events(event_id) ON DELETE CASCADE,
                    previous_hash TEXT NOT NULL,
                    current_hash TEXT NOT NULL,
                    link_mac TEXT
                )
            SQL);
    }

    private static function createIndexes(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_ts ON studio_events(timestamp_us DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_type ON studio_events(event_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_type_ts ON studio_events(event_type, timestamp_us DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_request ON studio_events(request_id) WHERE request_id IS NOT NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_trace ON studio_events(trace_id) WHERE trace_id IS NOT NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_job ON studio_events(job_id) WHERE job_id IS NOT NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_tenant ON studio_events(tenant_hash) WHERE tenant_hash IS NOT NULL');
    }

    private static function setSchemaVersion(PDO $pdo): void
    {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO studio_meta (key, value) VALUES (:key, :value)');
        $stmt->execute(['key' => 'schema_version', 'value' => self::SCHEMA_VERSION]);
    }
}
