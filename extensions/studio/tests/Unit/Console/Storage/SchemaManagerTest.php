<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Storage;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Storage\SchemaManager;

#[CoversClass(SchemaManager::class)]
final class SchemaManagerTest extends TestCase
{
    #[Test]
    public function ensureSchemaCreatesAllTables(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        SchemaManager::ensureSchema($pdo);

        $tables = $this->getTableNames($pdo);

        self::assertContains('studio_meta', $tables);
        self::assertContains('studio_events', $tables);
        self::assertContains('studio_chain', $tables);
    }

    #[Test]
    public function ensureSchemaCreatesIndexes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        SchemaManager::ensureSchema($pdo);

        $indexes = $this->getIndexNames($pdo);

        self::assertContains('idx_events_ts', $indexes);
        self::assertContains('idx_events_type', $indexes);
        self::assertContains('idx_events_type_ts', $indexes);
        self::assertContains('idx_events_request', $indexes);
        self::assertContains('idx_events_trace', $indexes);
        self::assertContains('idx_events_job', $indexes);
        self::assertContains('idx_events_tenant', $indexes);
    }

    #[Test]
    public function ensureSchemaSetsSchemaVersion(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        SchemaManager::ensureSchema($pdo);

        $stmt = $pdo->prepare('SELECT value FROM studio_meta WHERE key = :key');
        $stmt->execute(['key' => 'schema_version']);
        $version = $stmt->fetchColumn();

        self::assertSame('1', $version);
    }

    #[Test]
    public function ensureSchemaIsIdempotent(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        SchemaManager::ensureSchema($pdo);
        SchemaManager::ensureSchema($pdo);

        $tables = $this->getTableNames($pdo);
        self::assertContains('studio_events', $tables);
    }

    #[Test]
    public function studioEventsTableHasExpectedColumns(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        SchemaManager::ensureSchema($pdo);

        $stmt = $pdo->query('PRAGMA table_info(studio_events)');
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['name'];
        }

        self::assertContains('event_id', $columns);
        self::assertContains('event_type', $columns);
        self::assertContains('timestamp_us', $columns);
        self::assertContains('payload_json', $columns);
        self::assertContains('payload_hash', $columns);
        self::assertContains('ciphertext_hash', $columns);
        self::assertContains('tenant_hash', $columns);
    }

    /**
     * @return list<string>
     */
    private function getTableNames(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $names = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $names[] = $row['name'];
        }
        return $names;
    }

    /**
     * @return list<string>
     */
    private function getIndexNames(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%'");
        $names = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $names[] = $row['name'];
        }
        return $names;
    }
}
