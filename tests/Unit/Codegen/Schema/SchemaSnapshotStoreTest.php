<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\PropertyDefinition;
use Pulsar\Codegen\Schema\SchemaSnapshot;
use Pulsar\Codegen\Schema\SchemaSnapshotStore;

use function assert;
use function file_exists;
use function file_get_contents;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(SchemaSnapshotStore::class)]
final class SchemaSnapshotStoreTest extends TestCase
{
    private string $tempFile = '';

    protected function setUp(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'pulsar_snapshot_');
        assert($temp !== false);
        $this->tempFile = $temp . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    #[Test]
    public function loadReturnsNullWhenFileDoesNotExist(): void
    {
        $nonExistent = sys_get_temp_dir() . '/non_existent_snapshot_' . uniqid() . '.json';
        $store = new SchemaSnapshotStore($nonExistent);

        self::assertNull($store->load());
    }

    #[Test]
    public function existsReturnsFalseForMissingFile(): void
    {
        $nonExistent = sys_get_temp_dir() . '/non_existent_snapshot_' . uniqid() . '.json';
        $store = new SchemaSnapshotStore($nonExistent);

        self::assertFalse($store->exists());
    }

    #[Test]
    public function saveAndLoadRoundTrips(): void
    {
        $store = new SchemaSnapshotStore($this->tempFile);

        $snapshot = new SchemaSnapshot(
            entities: [
                'users' => new EntityDefinition(
                    className: 'User',
                    namespace: 'App\\Entity',
                    tableName: 'users',
                    properties: [
                        new PropertyDefinition(
                            name: 'id',
                            phpType: 'int',
                            columnName: 'id',
                            columnType: 'bigint',
                            nullable: false,
                            hasDefault: true,
                            defaultValue: null,
                            validationRules: [],
                            isFilterable: false,
                            isSortable: true,
                            length: null,
                            isPrimaryKey: true,
                        ),
                    ],
                    relationships: [],
                    primaryKey: 'id',
                    hasTimestamps: true,
                    hasSoftDeletes: false,
                    isAuditAware: false,
                ),
            ],
            version: '2',
        );

        $store->save($snapshot);

        self::assertTrue($store->exists());

        $loaded = $store->load();

        self::assertNotNull($loaded);
        self::assertSame($snapshot->version, $loaded->version);
        self::assertCount(1, $loaded->entities);
        self::assertSame('User', $loaded->entities['users']->className);
        self::assertSame($snapshot->hash(), $loaded->hash());
    }

    #[Test]
    public function savedFileIsPrettyPrintedJson(): void
    {
        $store = new SchemaSnapshotStore($this->tempFile);

        $snapshot = new SchemaSnapshot(
            entities: [
                'items' => new EntityDefinition(
                    className: 'Item',
                    namespace: 'App\\Entity',
                    tableName: 'items',
                    properties: [],
                    relationships: [],
                    primaryKey: 'id',
                    hasTimestamps: false,
                    hasSoftDeletes: false,
                    isAuditAware: false,
                ),
            ],
            version: '1',
        );

        $store->save($snapshot);

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        // Should be valid JSON
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        // Should end with a newline
        self::assertStringEndsWith("\n", $content);

        // Should be pretty-printed (contains newlines in the body)
        self::assertStringContainsString("\n    ", $content);
    }

    #[Test]
    public function getPathReturnsConfiguredPath(): void
    {
        $store = new SchemaSnapshotStore('/some/path/snapshot.json');

        self::assertSame('/some/path/snapshot.json', $store->getPath());
    }

    #[Test]
    public function saveCreatesDirectoryIfNeeded(): void
    {
        $dir = sys_get_temp_dir() . '/pulsar_test_' . uniqid();
        $path = $dir . '/nested/snapshot.json';
        $store = new SchemaSnapshotStore($path);

        $snapshot = new SchemaSnapshot(entities: [], version: '1');
        $store->save($snapshot);

        self::assertTrue(file_exists($path));

        // Cleanup
        unlink($path);
        rmdir($dir . '/nested');
        rmdir($dir);
    }
}
