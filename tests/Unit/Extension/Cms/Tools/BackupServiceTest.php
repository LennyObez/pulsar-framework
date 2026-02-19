<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Tools\Backup;
use Pulsar\Extension\Cms\Tools\BackupScope;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Extension\Cms\Tools\RestoreResult;

use function strlen;

#[CoversClass(Backup::class)]
#[CoversClass(BackupScope::class)]
#[CoversClass(RestoreResult::class)]
final class BackupServiceTest extends TestCase
{
    // ── Create produces Backup with hash ────────────────────────────

    #[Test]
    public function test_create_backup_produces_hash(): void
    {
        $service = $this->createBackupService();

        $backup = $service->createBackup(
            new BackupScope(includeContent: true, includeMedia: false),
            'actor-001',
        );

        self::assertNotEmpty($backup->id);
        self::assertNotEmpty($backup->hash);
        self::assertNotEmpty($backup->storagePath);
        self::assertSame('actor-001', $backup->createdBy);
        self::assertGreaterThan(0, $backup->size);
    }

    // ── Restore verifies hash ───────────────────────────────────────

    #[Test]
    public function test_restore_verifies_hash_success(): void
    {
        $service = $this->createBackupService();

        $backup = $service->createBackup(
            new BackupScope(includeContent: true, includeTaxonomies: true),
            'actor-001',
        );

        $result = $service->restoreBackup($backup->id, 'Restoring from backup', 'actor-002');

        self::assertNotEmpty($result->restoredCounts);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function test_restore_nonexistent_backup_throws(): void
    {
        $service = $this->createBackupService();

        $this->expectException(CmsException::class);

        $service->restoreBackup('nonexistent-id', 'reason', 'actor-001');
    }

    // ── BackupScope fromArray ───────────────────────────────────────

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
    }

    #[Test]
    public function test_backup_scope_to_array(): void
    {
        $scope = new BackupScope(
            includeContent: true,
            includeMedia: false,
            includeTaxonomies: true,
            includeMenus: true,
            includeSettings: true,
            tenantId: null,
        );

        $array = $scope->toArray();

        self::assertTrue($array['include_content']);
        self::assertFalse($array['include_media']);
        self::assertNull($array['tenant_id']);
    }

    #[Test]
    public function test_backup_scope_defaults(): void
    {
        $scope = new BackupScope();

        self::assertTrue($scope->includeContent);
        self::assertFalse($scope->includeMedia);
        self::assertTrue($scope->includeTaxonomies);
        self::assertTrue($scope->includeMenus);
        self::assertTrue($scope->includeSettings);
        self::assertNull($scope->tenantId);
    }

    // ── List backups ────────────────────────────────────────────────

    #[Test]
    public function test_list_backups(): void
    {
        $service = $this->createBackupService();

        $service->createBackup(new BackupScope(), 'actor-001');
        $service->createBackup(new BackupScope(), 'actor-002');

        $backups = $service->listBackups();

        self::assertCount(2, $backups);
    }

    private function createBackupService(): BackupServiceInterface
    {
        return new class implements BackupServiceInterface {
            /** @var array<string, Backup> */
            private array $backups = [];

            private int $counter = 0;

            public function createBackup(BackupScope $scope, string $actorId): Backup
            {
                $this->counter++;
                $id = "backup-{$this->counter}";
                $data = json_encode($scope->toArray(), JSON_THROW_ON_ERROR);
                $hash = hash('xxh128', $data);

                $backup = new Backup(
                    id: $id,
                    scope: $scope,
                    storagePath: "/backups/{$id}.json",
                    hash: $hash,
                    size: strlen($data),
                    createdAt: new DateTimeImmutable(),
                    createdBy: $actorId,
                );

                $this->backups[$id] = $backup;

                return $backup;
            }

            public function restoreBackup(string $backupId, string $reason, string $actorId): RestoreResult
            {
                $backup = $this->backups[$backupId] ?? null;

                if ($backup === null) {
                    throw CmsException::backupNotFound($backupId);
                }

                // Verify hash (in real implementation, re-hash file and compare)
                $data = json_encode($backup->scope->toArray(), JSON_THROW_ON_ERROR);
                $computedHash = hash('xxh128', $data);

                if ($computedHash !== $backup->hash) {
                    throw CmsException::backupTampered($backupId);
                }

                $counts = [];

                if ($backup->scope->includeContent) {
                    $counts['content'] = 0;
                }

                if ($backup->scope->includeTaxonomies) {
                    $counts['taxonomies'] = 0;
                }

                return new RestoreResult(
                    restoredCounts: $counts,
                    warnings: [],
                );
            }

            public function listBackups(?string $tenantId = null): array
            {
                if ($tenantId === null) {
                    return array_values($this->backups);
                }

                return array_values(array_filter(
                    $this->backups,
                    static fn(Backup $b) => $b->scope->tenantId === $tenantId,
                ));
            }

            public function deleteBackup(string $backupId, string $reason, string $actorId): void
            {
                if (!isset($this->backups[$backupId])) {
                    throw CmsException::backupNotFound($backupId);
                }

                unset($this->backups[$backupId]);
            }
        };
    }
}
