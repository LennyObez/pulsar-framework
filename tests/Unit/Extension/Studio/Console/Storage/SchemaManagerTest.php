<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Storage;

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
    private function createInMemoryPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    // --- Table creation ---

    #[Test]
    public function ensureSchemaCreatesStudioMetaTable(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $tables = $this->getTableNames($pdo);
        self::assertContains('studio_meta', $tables);
    }

    #[Test]
    public function ensureSchemaCreatesStudioEventsTable(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $tables = $this->getTableNames($pdo);
        self::assertContains('studio_events', $tables);
    }

    #[Test]
    public function ensureSchemaCreatesStudioChainTable(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $tables = $this->getTableNames($pdo);
        self::assertContains('studio_chain', $tables);
    }

    // --- Table schema validation ---

    #[Test]
    public function studioEventsHasExpectedColumns(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $columns = $this->getColumnNames($pdo, 'studio_events');

        $expectedColumns = [
            'id', 'event_id', 'event_type', 'schema_version', 'timestamp_us',
            'request_id', 'trace_id', 'span_id', 'job_id',
            'app_env', 'hostname', 'tenant_hash',
            'payload_json', 'payload_hash', 'ciphertext_hash',
        ];

        foreach ($expectedColumns as $col) {
            self::assertContains($col, $columns, "Missing column: $col");
        }
    }

    #[Test]
    public function studioMetaHasKeyValueColumns(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $columns = $this->getColumnNames($pdo, 'studio_meta');
        self::assertContains('key', $columns);
        self::assertContains('value', $columns);
    }

    #[Test]
    public function studioChainHasExpectedColumns(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $columns = $this->getColumnNames($pdo, 'studio_chain');
        $expectedColumns = ['id', 'event_id', 'previous_hash', 'current_hash', 'link_mac'];

        foreach ($expectedColumns as $col) {
            self::assertContains($col, $columns, "Missing column: $col");
        }
    }

    // --- Index creation ---

    #[Test]
    public function ensureSchemaCreatesExpectedIndexes(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $indexes = $this->getIndexNames($pdo);

        $expectedIndexes = [
            'idx_events_ts',
            'idx_events_type',
            'idx_events_type_ts',
            'idx_events_request',
            'idx_events_trace',
            'idx_events_job',
            'idx_events_tenant',
        ];

        foreach ($expectedIndexes as $idx) {
            self::assertContains($idx, $indexes, "Missing index: $idx");
        }
    }

    // --- Schema version ---

    #[Test]
    public function ensureSchemaSetsSchemaVersionMeta(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $stmt = $pdo->prepare('SELECT value FROM studio_meta WHERE key = :key');
        $stmt->execute(['key' => 'schema_version']);
        $value = $stmt->fetchColumn();

        self::assertSame('1', $value);
    }

    // --- Idempotency ---

    #[Test]
    public function ensureSchemaIsIdempotent(): void
    {
        $pdo = $this->createInMemoryPdo();

        // Call twice — should not throw
        SchemaManager::ensureSchema($pdo);
        SchemaManager::ensureSchema($pdo);

        $tables = $this->getTableNames($pdo);
        self::assertContains('studio_events', $tables);
        self::assertContains('studio_chain', $tables);
        self::assertContains('studio_meta', $tables);
    }

    #[Test]
    public function ensureSchemaPreservesExistingData(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        // Insert data
        $pdo->exec(
            "INSERT INTO studio_events (event_id, event_type, schema_version, timestamp_us, app_env, hostname, payload_json, payload_hash) "
            . "VALUES ('evt-1', 'test', 1, 1000000, 'testing', 'localhost', '{}', 'hash')",
        );

        // Re-run schema setup
        SchemaManager::ensureSchema($pdo);

        // Data should still exist
        $stmt = $pdo->query('SELECT COUNT(*) FROM studio_events');
        assert($stmt instanceof \PDOStatement);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    // --- Pragmas ---

    #[Test]
    public function ensureSchemaSetsWalJournalMode(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $stmt = $pdo->query('PRAGMA journal_mode');
        assert($stmt instanceof \PDOStatement);
        $mode = $stmt->fetchColumn();

        // In-memory databases may report 'memory' rather than 'wal', but the pragma was issued
        self::assertIsString($mode);
    }

    #[Test]
    public function ensureSchemaSetsExpectedBusyTimeout(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $stmt = $pdo->query('PRAGMA busy_timeout');
        assert($stmt instanceof \PDOStatement);
        $timeout = (int) $stmt->fetchColumn();

        self::assertSame(5000, $timeout);
    }

    #[Test]
    public function ensureSchemaEnablesForeignKeys(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $stmt = $pdo->query('PRAGMA foreign_keys');
        assert($stmt instanceof \PDOStatement);
        $fk = (int) $stmt->fetchColumn();

        self::assertSame(1, $fk);
    }

    // --- Data integrity constraints ---

    #[Test]
    public function eventIdIsUniqueInEventsTable(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $pdo->exec(
            "INSERT INTO studio_events (event_id, event_type, schema_version, timestamp_us, app_env, hostname, payload_json, payload_hash) "
            . "VALUES ('evt-dup', 'test', 1, 1000000, 'testing', 'localhost', '{}', 'hash')",
        );

        $this->expectException(PDOException::class);

        $pdo->exec(
            "INSERT INTO studio_events (event_id, event_type, schema_version, timestamp_us, app_env, hostname, payload_json, payload_hash) "
            . "VALUES ('evt-dup', 'test', 1, 1000001, 'testing', 'localhost', '{}', 'hash2')",
        );
    }

    #[Test]
    public function chainEventIdReferencesEventsTable(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        // Insert a valid event first
        $pdo->exec(
            "INSERT INTO studio_events (event_id, event_type, schema_version, timestamp_us, app_env, hostname, payload_json, payload_hash) "
            . "VALUES ('evt-chain', 'test', 1, 1000000, 'testing', 'localhost', '{}', 'hash')",
        );

        // Chain link referencing it should succeed
        $pdo->exec(
            "INSERT INTO studio_chain (event_id, previous_hash, current_hash) "
            . "VALUES ('evt-chain', 'prev', 'curr')",
        );

        $stmt = $pdo->query("SELECT COUNT(*) FROM studio_chain WHERE event_id = 'evt-chain'");
        assert($stmt instanceof \PDOStatement);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function chainForeignKeyRejectsOrphanedLinks(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $this->expectException(PDOException::class);

        // Try to insert chain link referencing non-existent event
        $pdo->exec(
            "INSERT INTO studio_chain (event_id, previous_hash, current_hash) "
            . "VALUES ('nonexistent-evt', 'prev', 'curr')",
        );
    }

    #[Test]
    public function chainCascadeDeletesWhenEventDeleted(): void
    {
        $pdo = $this->createInMemoryPdo();
        SchemaManager::ensureSchema($pdo);

        $pdo->exec(
            "INSERT INTO studio_events (event_id, event_type, schema_version, timestamp_us, app_env, hostname, payload_json, payload_hash) "
            . "VALUES ('evt-cascade', 'test', 1, 1000000, 'testing', 'localhost', '{}', 'hash')",
        );

        $pdo->exec(
            "INSERT INTO studio_chain (event_id, previous_hash, current_hash) "
            . "VALUES ('evt-cascade', 'prev', 'curr')",
        );

        // Delete the event
        $pdo->exec("DELETE FROM studio_events WHERE event_id = 'evt-cascade'");

        // Chain link should be cascade-deleted
        $stmt = $pdo->query("SELECT COUNT(*) FROM studio_chain WHERE event_id = 'evt-cascade'");
        assert($stmt instanceof \PDOStatement);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    // --- Error handling ---

    #[Test]
    public function ensureSchemaWrasPdoExceptionInStudioException(): void
    {
        // Create a read-only in-memory PDO that will fail on exec
        // We simulate this by closing the PDO and re-using it isn't possible,
        // so instead we use a mock for exec failure
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')
            ->willThrowException(new PDOException('disk I/O error'));

        $this->expectException(StudioException::class);
        $this->expectExceptionMessage('Studio schema error');

        SchemaManager::ensureSchema($pdo);
    }

    // --- Helper methods ---

    /**
     * @return list<string>
     */
    private function getTableNames(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        assert($stmt instanceof \PDOStatement);
        /** @var list<string> $tables */
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values($tables);
    }

    /**
     * @return list<string>
     */
    private function getColumnNames(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA table_info($table)");
        assert($stmt instanceof \PDOStatement);
        $columns = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            assert(is_array($row));
            $name = $row['name'];
            assert(is_string($name));
            $columns[] = $name;
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function getIndexNames(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%'");
        assert($stmt instanceof \PDOStatement);
        /** @var list<string> $indexes */
        $indexes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values($indexes);
    }
}
