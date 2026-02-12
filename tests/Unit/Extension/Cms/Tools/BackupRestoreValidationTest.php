<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Tools\BackupService;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Tools\BackupScope;

use function bin2hex;
use function json_encode;
use function sodium_crypto_generichash;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_GENERICHASH_BYTES;

#[CoversClass(BackupService::class)]
final class BackupRestoreValidationTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    /** @var array<string, string> */
    private array $diskStorage = [];

    private BackupService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->diskStorage = [];

        $disk = $this->createStub(MediaDiskInterface::class);
        $storage = &$this->diskStorage;
        $disk->method('exists')->willReturnCallback(static function (string $path) use (&$storage): bool {
            /** @var array<string, string> $storage */
            return isset($storage[$path]);
        });
        $disk->method('read')->willReturnCallback(static function (string $path) use (&$storage): string {
            /** @var array<string, string> $storage */
            return $storage[$path] ?? '';
        });
        $disk->method('write')->willReturnCallback(static function (string $path, string $contents) use (&$storage): void {
            /** @var array<string, string> $storage */
            $storage[$path] = $contents;
        });

        $this->service = new BackupService($this->connection, $disk, null);
    }

    #[Test]
    #[DataProvider('maliciousColumnNames')]
    public function restore_rejects_malicious_column_names(string $maliciousColumn): void
    {
        $backupId = 'test-backup-001';
        $this->seedBackupWithMaliciousColumns($backupId, [$maliciousColumn => 'payload']);

        $this->connection->method('transaction')
            ->willReturnCallback(function (callable $callback): mixed {
                return $callback($this->connection);
            });

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Invalid column name');

        $this->service->restoreBackup($backupId, 'test reason', 'actor-001');
    }

    /** @return iterable<string, array{string}> */
    public static function maliciousColumnNames(): iterable
    {
        yield 'SQL injection via semicolon' => ['id); DROP TABLE cms_contents; --'];
        yield 'SQL injection via quotes' => ["id' OR '1'='1"];
        yield 'SQL injection via parens' => ['id) UNION SELECT * FROM users --'];
        yield 'leading digit' => ['1column'];
        yield 'space in name' => ['col name'];
        yield 'hyphen in name' => ['col-name'];
        yield 'dot notation' => ['table.column'];
        yield 'backtick escape' => ['col`; DROP TABLE x;--'];
        yield 'double quote escape' => ['col"; DROP TABLE x;--'];
        yield 'newline injection' => ["col\nDROP TABLE x"];
        yield 'null byte injection' => ["col\x00DROP"];
    }

    #[Test]
    public function restore_accepts_valid_column_names(): void
    {
        $backupId = 'test-backup-002';
        $this->seedBackupWithMaliciousColumns($backupId, [
            'id' => 'abc-123',
            'title' => 'Hello',
            'created_at' => '2025-01-01',
            'content_type_id' => 'page',
        ]);

        $executedSql = [];

        $this->connection->method('transaction')
            ->willReturnCallback(function (callable $callback): mixed {
                return $callback($this->connection);
            });

        $this->connection->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;
                return 1;
            });

        $result = $this->service->restoreBackup($backupId, 'test restore', 'actor-001');

        self::assertArrayHasKey('contents', $result->restoredCounts);
        self::assertSame(1, $result->restoredCounts['contents']);

        // Verify the INSERT SQL uses quoted identifiers
        $insertSql = '';
        foreach ($executedSql as $sql) {
            if (str_starts_with($sql, 'INSERT')) {
                $insertSql = $sql;
                break;
            }
        }

        self::assertNotEmpty($insertSql);
        self::assertStringContainsString('"id"', $insertSql);
        self::assertStringContainsString('"title"', $insertSql);
        self::assertStringContainsString('"cms_contents"', $insertSql);
    }

    #[Test]
    public function restore_rejects_column_name_exceeding_64_chars(): void
    {
        $backupId = 'test-backup-003';
        $longColumn = str_repeat('a', 65);
        $this->seedBackupWithMaliciousColumns($backupId, [$longColumn => 'value']);

        $this->connection->method('transaction')
            ->willReturnCallback(function (callable $callback): mixed {
                return $callback($this->connection);
            });

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Invalid column name');

        $this->service->restoreBackup($backupId, 'test', 'actor-001');
    }

    #[Test]
    public function restore_accepts_column_name_at_64_chars(): void
    {
        $backupId = 'test-backup-004';
        $exactColumn = str_repeat('a', 64);
        $this->seedBackupWithMaliciousColumns($backupId, [$exactColumn => 'value']);

        $this->connection->method('transaction')
            ->willReturnCallback(function (callable $callback): mixed {
                return $callback($this->connection);
            });

        $this->connection->method('execute')->willReturn(1);

        $result = $this->service->restoreBackup($backupId, 'test', 'actor-001');

        self::assertSame(1, $result->restoredCounts['contents']);
    }

    /**
     * Seed the in-memory disk with a backup containing the given row data
     * in the 'contents' table (which maps to cms_contents).
     *
     * The backup JSON and metadata get valid hashes so hash verification passes.
     *
     * @param array<string, mixed> $rowData
     */
    private function seedBackupWithMaliciousColumns(string $backupId, array $rowData): void
    {
        $scope = new BackupScope(
            includeContent: true,
            includeMedia: false,
            includeTaxonomies: false,
            includeMenus: false,
            includeSettings: false,
            includeCommerce: false,
        );

        $backupJson = json_encode([
            'version' => '1.0',
            'scope' => $scope->toArray(),
            'created_at' => '2025-01-01T00:00:00+00:00',
            'created_by' => 'actor-seed',
            'tables' => [
                'contents' => [$rowData],
                'content_translations' => [],
                'content_blocks' => [],
                'content_taxonomy_terms' => [],
                'comments' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $hash = bin2hex(sodium_crypto_generichash($backupJson, '', SODIUM_CRYPTO_GENERICHASH_BYTES));

        $storagePath = 'backups/cms/' . $backupId . '.json';
        $metadataPath = 'backups/cms/' . $backupId . '.meta.json';

        $this->diskStorage[$storagePath] = $backupJson;
        $this->diskStorage[$metadataPath] = json_encode([
            'id' => $backupId,
            'scope' => $scope->toArray(),
            'storage_path' => $storagePath,
            'hash' => $hash,
            'size' => strlen($backupJson),
            'created_at' => '2025-01-01T00:00:00+00:00',
            'created_by' => 'actor-seed',
        ], JSON_THROW_ON_ERROR);
    }
}
