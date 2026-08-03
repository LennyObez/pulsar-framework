<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Storage;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Storage\SchemaManager;
use Pulsar\Extension\Studio\Exception\StudioException;

#[CoversClass(SchemaManager::class)]
final class SchemaManagerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    #[Test]
    public function ensureSchemaCreatesStudioEventsTable(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $tables = $this->getTableNames();

        self::assertContains('studio_events', $tables);
    }

    #[Test]
    public function ensureSchemaCreatesStudioChainTable(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $tables = $this->getTableNames();

        self::assertContains('studio_chain', $tables);
    }

    #[Test]
    public function ensureSchemaCreatesStudioMetaTable(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $tables = $this->getTableNames();

        self::assertContains('studio_meta', $tables);
    }

    #[Test]
    public function ensureSchemaCreatesEventsTableWithCorrectColumns(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $columns = $this->getTableColumns('studio_events');

        self::assertContains('id', $columns);
        self::assertContains('event_id', $columns);
        self::assertContains('event_type', $columns);
        self::assertContains('schema_version', $columns);
        self::assertContains('timestamp_us', $columns);
        self::assertContains('request_id', $columns);
        self::assertContains('trace_id', $columns);
        self::assertContains('span_id', $columns);
        self::assertContains('job_id', $columns);
        self::assertContains('app_env', $columns);
        self::assertContains('hostname', $columns);
        self::assertContains('tenant_hash', $columns);
        self::assertContains('payload_json', $columns);
        self::assertContains('payload_hash', $columns);
        self::assertContains('ciphertext_hash', $columns);
    }

    #[Test]
    public function ensureSchemaCreatesChainTableWithCorrectColumns(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $columns = $this->getTableColumns('studio_chain');

        self::assertContains('id', $columns);
        self::assertContains('event_id', $columns);
        self::assertContains('previous_hash', $columns);
        self::assertContains('current_hash', $columns);
        self::assertContains('link_mac', $columns);
    }

    #[Test]
    public function ensureSchemaCreatesMetaTableWithCorrectColumns(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $columns = $this->getTableColumns('studio_meta');

        self::assertContains('key', $columns);
        self::assertContains('value', $columns);
    }

    #[Test]
    public function ensureSchemaCreatesIndexes(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $indexes = $this->getIndexNames();

        self::assertContains('idx_events_ts', $indexes);
        self::assertContains('idx_events_type', $indexes);
        self::assertContains('idx_events_type_ts', $indexes);
        self::assertContains('idx_events_request', $indexes);
        self::assertContains('idx_events_trace', $indexes);
        self::assertContains('idx_events_job', $indexes);
        self::assertContains('idx_events_tenant', $indexes);
    }

    #[Test]
    public function ensureSchemaIsIdempotent(): void
    {
        // Call ensureSchema multiple times
        SchemaManager::ensureSchema($this->pdo);
        SchemaManager::ensureSchema($this->pdo);
        SchemaManager::ensureSchema($this->pdo);

        // Should not throw and tables should still exist
        $tables = $this->getTableNames();

        self::assertContains('studio_events', $tables);
        self::assertContains('studio_chain', $tables);
        self::assertContains('studio_meta', $tables);
    }

    #[Test]
    public function ensureSchemaSetsSchemaVersionInMeta(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $stmt = $this->pdo->prepare("SELECT value FROM studio_meta WHERE key = 'schema_version'");
        $stmt->execute();
        /** @var string|false $version */
        $version = $stmt->fetchColumn();

        self::assertSame('1', $version);
    }

    #[Test]
    public function ensureSchemaSchemaVersionIsNotOverwritten(): void
    {
        // First call sets version
        SchemaManager::ensureSchema($this->pdo);

        // Manually update version
        $this->pdo->exec("UPDATE studio_meta SET value = '999' WHERE key = 'schema_version'");

        // Second call should not overwrite due to INSERT OR IGNORE
        SchemaManager::ensureSchema($this->pdo);

        $stmt = $this->pdo->prepare("SELECT value FROM studio_meta WHERE key = 'schema_version'");
        $stmt->execute();
        /** @var string|false $version */
        $version = $stmt->fetchColumn();

        self::assertSame('999', $version);
    }

    #[Test]
    public function ensureSchemaSetsJournalMode(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $stmt = $this->pdo->query('PRAGMA journal_mode');
        self::assertNotFalse($stmt);
        /** @var string|false $mode */
        $mode = $stmt->fetchColumn();

        // In-memory SQLite returns 'memory' instead of 'wal'
        // Both are valid outcomes - memory for :memory:, wal for file-based
        self::assertContains($mode, ['wal', 'memory']);
    }

    #[Test]
    public function ensureSchemaSetsBusyTimeout(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $stmt = $this->pdo->query('PRAGMA busy_timeout');
        self::assertNotFalse($stmt);
        /** @var int|string|false $timeout */
        $timeout = $stmt->fetchColumn();

        self::assertSame(5000, (int) $timeout);
    }

    #[Test]
    public function ensureSchemaSetsNormalSynchronous(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $stmt = $this->pdo->query('PRAGMA synchronous');
        self::assertNotFalse($stmt);
        /** @var int|string|false $sync */
        $sync = $stmt->fetchColumn();

        // NORMAL = 1
        self::assertSame(1, (int) $sync);
    }

    #[Test]
    public function ensureSchemaEnablesForeignKeys(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $stmt = $this->pdo->query('PRAGMA foreign_keys');
        self::assertNotFalse($stmt);
        /** @var int|string|false $fk */
        $fk = $stmt->fetchColumn();

        self::assertSame(1, (int) $fk);
    }

    #[Test]
    public function ensureSchemaCacheSize(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $stmt = $this->pdo->query('PRAGMA cache_size');
        self::assertNotFalse($stmt);
        /** @var int|string|false $cacheSize */
        $cacheSize = $stmt->fetchColumn();

        // Negative value means KB, -2000 = 2000KB
        self::assertSame(-2000, (int) $cacheSize);
    }

    #[Test]
    public function ensureSchemaEventsTableHasEventIdUnique(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        // Insert a row
        $this->pdo->exec(
            'INSERT INTO studio_events (event_id, event_type, schema_version, timestamp_us, app_env, hostname, payload_json, payload_hash) '
            . "VALUES ('unique-id', 'test', 1, 1234567890, 'test', 'localhost', '{}', 'hash')",
        );

        // Try to insert duplicate - should fail
        $this->expectException(PDOException::class);

        $this->pdo->exec(
            'INSERT INTO studio_events (event_id, event_type, schema_version, timestamp_us, app_env, hostname, payload_json, payload_hash) '
            . "VALUES ('unique-id', 'test', 1, 1234567891, 'test', 'localhost', '{}', 'hash2')",
        );
    }

    #[Test]
    public function ensureSchemaChainTableHasForeignKeyToEvents(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        // Insert an event
        $this->pdo->exec(
            'INSERT INTO studio_events (event_id, event_type, schema_version, timestamp_us, app_env, hostname, payload_json, payload_hash) '
            . "VALUES ('event-1', 'test', 1, 1234567890, 'test', 'localhost', '{}', 'hash')",
        );

        // Insert chain link referencing the event
        $this->pdo->exec(
            'INSERT INTO studio_chain (event_id, previous_hash, current_hash) '
            . "VALUES ('event-1', 'prev', 'curr')",
        );

        // Try to insert chain link with non-existent event - should fail
        $this->expectException(PDOException::class);

        $this->pdo->exec(
            'INSERT INTO studio_chain (event_id, previous_hash, current_hash) '
            . "VALUES ('nonexistent', 'prev', 'curr')",
        );
    }

    #[Test]
    public function ensureSchemaChainDeletesCascadeOnEventDelete(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        // Insert event and chain link
        $this->pdo->exec(
            'INSERT INTO studio_events (event_id, event_type, schema_version, timestamp_us, app_env, hostname, payload_json, payload_hash) '
            . "VALUES ('event-1', 'test', 1, 1234567890, 'test', 'localhost', '{}', 'hash')",
        );
        $this->pdo->exec(
            'INSERT INTO studio_chain (event_id, previous_hash, current_hash) '
            . "VALUES ('event-1', 'prev', 'curr')",
        );

        // Delete event
        $this->pdo->exec("DELETE FROM studio_events WHERE event_id = 'event-1'");

        // Chain link should be deleted too
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM studio_chain WHERE event_id = 'event-1'");
        self::assertNotFalse($stmt);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function ensureSchemaThrowsStudioExceptionOnPdoError(): void
    {
        // Create a stub PDO that throws on exec
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willThrowException(new PDOException('Database error'));

        $this->expectException(StudioException::class);
        $this->expectExceptionMessageIsOrContains('Studio schema error:');

        SchemaManager::ensureSchema($pdo);
    }

    #[Test]
    public function ensureSchemaMetaTableHasPrimaryKey(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        // Insert a row
        $this->pdo->exec("INSERT INTO studio_meta (key, value) VALUES ('test_key', 'test_value')");

        // Try to insert duplicate key - should fail due to PRIMARY KEY
        $this->expectException(PDOException::class);

        $this->pdo->exec("INSERT INTO studio_meta (key, value) VALUES ('test_key', 'other_value')");
    }

    #[Test]
    public function ensureSchemaCanInsertAndQueryEvents(): void
    {
        SchemaManager::ensureSchema($this->pdo);

        $stmt = $this->pdo->prepare(
            'INSERT INTO studio_events (event_id, event_type, schema_version, timestamp_us, request_id, trace_id, span_id, job_id, app_env, hostname, tenant_hash, payload_json, payload_hash, ciphertext_hash) '
            . 'VALUES (:event_id, :event_type, :schema_version, :timestamp_us, :request_id, :trace_id, :span_id, :job_id, :app_env, :hostname, :tenant_hash, :payload_json, :payload_hash, :ciphertext_hash)',
        );

        $stmt->execute([
            'event_id' => 'test-event',
            'event_type' => 'http.request',
            'schema_version' => 1,
            'timestamp_us' => 1234567890123456,
            'request_id' => 'req-123',
            'trace_id' => 'trace-456',
            'span_id' => 'span-789',
            'job_id' => null,
            'app_env' => 'testing',
            'hostname' => 'localhost',
            'tenant_hash' => null,
            'payload_json' => '{"key":"value"}',
            'payload_hash' => 'abcdef123456',
            'ciphertext_hash' => null,
        ]);

        $query = $this->pdo->query("SELECT * FROM studio_events WHERE event_id = 'test-event'");
        self::assertNotFalse($query);
        /** @var array<string, mixed>|false $row */
        $row = $query->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('test-event', $row['event_id']);
        self::assertSame('http.request', $row['event_type']);
        self::assertSame(1234567890123456, $row['timestamp_us']);
    }

    /**
     * @return list<string>
     */
    private function getTableNames(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        self::assertNotFalse($stmt);

        /** @var list<string> */
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return list<string>
     */
    private function getTableColumns(string $table): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info({$table})");
        self::assertNotFalse($stmt);

        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            /** @var array{name: string} $row */
            $columns[] = $row['name'];
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function getIndexNames(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%'");
        self::assertNotFalse($stmt);

        /** @var list<string> */
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
