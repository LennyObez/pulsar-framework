<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Tools\Backup;
use Pulsar\Extension\Cms\Tools\BackupScope;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Extension\Cms\Tools\RestoreResult;

use function array_filter;
use function array_values;
use function count;
use function json_encode;
use function sodium_crypto_generichash;
use function strlen;

/**
 * E2E: Backup and restore — create backup -> delete content -> restore -> verify integrity.
 */
#[Group('e2e-cms')]
final class BackupRestoreTest extends TestCase
{
    #[Test]
    public function test_full_backup_and_restore_lifecycle(): void
    {
        $contentStore = new E2EBackupContentStore();
        $service = new E2EBackupService($contentStore);

        // Step 1: Create content
        $article1 = Content::create(
            id: 'backup-art-001',
            contentType: ContentType::Article,
            authorId: 'author-backup',
        )->publish();

        $article2 = Content::create(
            id: 'backup-art-002',
            contentType: ContentType::Article,
            authorId: 'author-backup',
        )->publish();

        $page = Content::create(
            id: 'backup-page-001',
            contentType: ContentType::Page,
            authorId: 'author-backup',
        );

        $contentStore->save($article1);
        $contentStore->save($article2);
        $contentStore->save($page);

        self::assertCount(3, $contentStore->all());

        // Step 2: Create backup
        $scope = new BackupScope(
            includeContent: true,
            includeMedia: false,
            includeTaxonomies: true,
            includeMenus: true,
            includeSettings: true,
        );

        $backup = $service->createBackup($scope, 'admin-backup');

        self::assertNotEmpty($backup->id);
        self::assertNotEmpty($backup->hash);
        self::assertGreaterThan(0, $backup->size);
        self::assertTrue($backup->scope->includeContent);
        self::assertFalse($backup->scope->includeMedia);

        // Step 3: Delete all content
        $contentStore->deleteAll();
        self::assertCount(0, $contentStore->all());

        // Step 4: Restore from backup
        $result = $service->restoreBackup($backup->id, 'Accidental deletion recovery', 'admin-backup');

        self::assertSame(3, $result->restoredCounts['content']);
        self::assertSame([], $result->warnings);

        // Step 5: Verify restored content integrity
        self::assertCount(3, $contentStore->all());

        $restoredArticle1 = $contentStore->findById('backup-art-001');
        self::assertNotNull($restoredArticle1);
        self::assertSame(PublishingStatus::Published, $restoredArticle1->status);

        $restoredPage = $contentStore->findById('backup-page-001');
        self::assertNotNull($restoredPage);
        self::assertSame(PublishingStatus::Draft, $restoredPage->status);
    }

    #[Test]
    public function test_backup_list_and_delete(): void
    {
        $contentStore = new E2EBackupContentStore();
        $service = new E2EBackupService($contentStore);

        $scope = new BackupScope();

        $backup1 = $service->createBackup($scope, 'admin-1');
        $backup2 = $service->createBackup($scope, 'admin-2');

        $backups = $service->listBackups();
        self::assertCount(2, $backups);

        $service->deleteBackup($backup1->id, 'Cleanup old backup', 'admin-1');

        $remaining = $service->listBackups();
        self::assertCount(1, $remaining);
        self::assertSame($backup2->id, $remaining[0]->id);
    }

    #[Test]
    public function test_restore_nonexistent_backup_throws(): void
    {
        $contentStore = new E2EBackupContentStore();
        $service = new E2EBackupService($contentStore);

        $this->expectException(CmsException::class);
        $service->restoreBackup('nonexistent-backup-id', 'reason', 'admin');
    }

    #[Test]
    public function test_tampered_backup_rejected(): void
    {
        $contentStore = new E2EBackupContentStore();
        $service = new E2EBackupService($contentStore);

        $content = Content::create(
            id: 'backup-tamper-001',
            contentType: ContentType::Article,
            authorId: 'author-tamper',
        );
        $contentStore->save($content);

        $backup = $service->createBackup(new BackupScope(), 'admin');

        // Tamper with the backup data
        $service->tamperBackup($backup->id);

        $this->expectException(CmsException::class);
        $service->restoreBackup($backup->id, 'Tampered restore attempt', 'admin');
    }

    #[Test]
    public function test_backup_scope_from_array(): void
    {
        $scope = BackupScope::fromArray([
            'include_content' => true,
            'include_media' => true,
            'include_taxonomies' => false,
            'include_menus' => true,
            'include_settings' => false,
            'tenant_id' => 'tenant-001',
        ]);

        self::assertTrue($scope->includeContent);
        self::assertTrue($scope->includeMedia);
        self::assertFalse($scope->includeTaxonomies);
        self::assertTrue($scope->includeMenus);
        self::assertFalse($scope->includeSettings);
        self::assertSame('tenant-001', $scope->tenantId);

        // Round-trip
        $array = $scope->toArray();
        self::assertTrue($array['include_content']);
        self::assertTrue($array['include_media']);
        self::assertSame('tenant-001', $array['tenant_id']);
    }
}

/**
 * @internal In-memory content store for backup E2E tests.
 */
final class E2EBackupContentStore
{
    /** @var array<string, Content> */
    private array $contents = [];

    public function save(Content $content): void
    {
        $this->contents[$content->id] = $content;
    }

    public function findById(string $id): ?Content
    {
        return $this->contents[$id] ?? null;
    }

    /** @return list<Content> */
    public function all(): array
    {
        return array_values($this->contents);
    }

    public function deleteAll(): void
    {
        $this->contents = [];
    }

    /**
     * @param list<Content> $contents
     */
    public function restoreAll(array $contents): void
    {
        $this->contents = [];

        foreach ($contents as $c) {
            $this->contents[$c->id] = $c;
        }
    }
}

/**
 * @internal In-memory backup service for E2E tests.
 */
final class E2EBackupService implements BackupServiceInterface
{
    /** @var array<string, array{backup: Backup, data: string}> */
    private array $backups = [];

    private int $counter = 0;

    public function __construct(
        private readonly E2EBackupContentStore $contentStore,
    ) {}

    public function createBackup(BackupScope $scope, string $actorId): Backup
    {
        $this->counter++;
        $id = "backup-e2e-{$this->counter}";

        $data = json_encode([
            'content' => array_map(
                static fn(Content $c) => ['id' => $c->id, 'type' => $c->contentType->value, 'status' => $c->status->value, 'authorId' => $c->authorId],
                $this->contentStore->all(),
            ),
        ], JSON_THROW_ON_ERROR);

        $hash = bin2hex(sodium_crypto_generichash($data));

        $backup = new Backup(
            id: $id,
            scope: $scope,
            storagePath: "/backups/{$id}.json",
            hash: $hash,
            size: strlen($data),
            createdAt: new DateTimeImmutable(),
            createdBy: $actorId,
        );

        $this->backups[$id] = ['backup' => $backup, 'data' => $data];

        return $backup;
    }

    public function restoreBackup(string $backupId, string $reason, string $actorId): RestoreResult
    {
        if (!isset($this->backups[$backupId])) {
            throw CmsException::backupNotFound($backupId);
        }

        $entry = $this->backups[$backupId];
        $data = $entry['data'];
        $expectedHash = $entry['backup']->hash;

        // Verify integrity
        $actualHash = bin2hex(sodium_crypto_generichash($data));

        if ($actualHash !== $expectedHash) {
            throw CmsException::backupTampered($backupId);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        /** @var list<array{id: string, type: string, status: string, authorId: string}> $contentItems */
        $contentItems = $decoded['content'] ?? [];

        $restored = [];
        foreach ($contentItems as $item) {
            $content = Content::create(
                id: $item['id'],
                contentType: ContentType::from($item['type']),
                authorId: $item['authorId'],
            );

            if ($item['status'] === PublishingStatus::Published->value) {
                $content = $content->publish();
            }

            $restored[] = $content;
        }

        $this->contentStore->restoreAll($restored);

        return new RestoreResult(
            restoredCounts: ['content' => count($restored)],
            warnings: [],
        );
    }

    public function listBackups(?string $tenantId = null): array
    {
        $backups = array_map(
            static fn(array $entry) => $entry['backup'],
            $this->backups,
        );

        if ($tenantId !== null) {
            $backups = array_filter(
                $backups,
                static fn(Backup $b) => $b->scope->tenantId === $tenantId,
            );
        }

        return array_values($backups);
    }

    public function deleteBackup(string $backupId, string $reason, string $actorId): void
    {
        if (!isset($this->backups[$backupId])) {
            throw CmsException::backupNotFound($backupId);
        }

        unset($this->backups[$backupId]);
    }

    /**
     * Tamper with backup data for testing integrity checks.
     */
    public function tamperBackup(string $backupId): void
    {
        if (isset($this->backups[$backupId])) {
            $this->backups[$backupId]['data'] = '{"content":[{"id":"hacked","type":"article","status":"draft","authorId":"attacker"}]}';
        }
    }
}
