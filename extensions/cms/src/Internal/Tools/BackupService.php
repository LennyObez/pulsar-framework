<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Tools\Backup;
use Pulsar\Extension\Cms\Tools\BackupScope;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Extension\Cms\Tools\RestoreResult;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function array_filter;
use function array_keys;
use function array_map;
use function array_reverse;
use function array_values;
use function bin2hex;
use function count;
use function dechex;
use function hash_equals;
use function hexdec;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function microtime;
use function random_bytes;
use function sodium_crypto_generichash;
use function sprintf;
use function str_pad;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_GENERICHASH_BYTES;
use const STR_PAD_LEFT;

/**
 * CMS backup and restore service.
 *
 * Dumps CMS database tables to JSON with BLAKE2b integrity hashes,
 * stored in private media disk. Restore validates hash before applying.
 */
#[Internal(reason: 'Backup internals — use BackupServiceInterface')]
final readonly class BackupService implements BackupServiceInterface
{
    private const string BACKUP_DIR = 'backups/cms';

    /** CMS tables to back up, in dependency-safe order for restore. */
    private const array CMS_TABLES = [
        'taxonomies' => 'cms_taxonomies',
        'taxonomy_translations' => 'cms_taxonomy_translations',
        'taxonomy_terms' => 'cms_taxonomy_terms',
        'taxonomy_term_translations' => 'cms_taxonomy_term_translations',
        'contents' => 'cms_contents',
        'content_translations' => 'cms_content_translations',
        'content_blocks' => 'cms_content_blocks',
        'content_taxonomy_terms' => 'cms_content_taxonomy_terms',
        'menus' => 'cms_menus',
        'menu_translations' => 'cms_menu_translations',
        'menu_items' => 'cms_menu_items',
        'menu_item_translations' => 'cms_menu_item_translations',
        'media' => 'cms_media',
        'media_translations' => 'cms_media_translations',
        'media_derivatives' => 'cms_media_derivatives',
        'redirects' => 'cms_redirects',
        'settings' => 'cms_settings',
        'settings_history' => 'cms_settings_history',
        'comments' => 'cms_comments',
    ];

    public function __construct(
        private ConnectionInterface $connection,
        private MediaDiskInterface $disk,
        private ?AuditLoggerInterface $auditLogger,
    ) {}

    public function createBackup(BackupScope $scope, string $actorId): Backup
    {
        $data = [];
        $tables = $this->getTablesForScope($scope);

        foreach ($tables as $label => $table) {
            $tenantFilter = '';
            $bindings = [];

            if ($scope->tenantId !== null && $this->tableHasTenantColumn($table)) {
                $tenantFilter = ' WHERE tenant_id = :tenant_id';
                $bindings['tenant_id'] = $scope->tenantId;
            }

            $result = $this->connection->query(
                "SELECT * FROM {$table}{$tenantFilter}",
                $bindings,
            );

            $data[$label] = $result->all();
        }

        $json = json_encode([
            'version' => '1.0',
            'scope' => $scope->toArray(),
            'created_at' => new DateTimeImmutable()->format('c'),
            'created_by' => $actorId,
            'tables' => $data,
        ], JSON_THROW_ON_ERROR);

        $hash = bin2hex(sodium_crypto_generichash($json, '', SODIUM_CRYPTO_GENERICHASH_BYTES));
        $backupId = $this->generateId();
        $storagePath = self::BACKUP_DIR . '/' . $backupId . '.json';

        $this->disk->write($storagePath, $json);

        $backup = new Backup(
            id: $backupId,
            scope: $scope,
            storagePath: $storagePath,
            hash: $hash,
            size: strlen($json),
            createdAt: new DateTimeImmutable(),
            createdBy: $actorId,
        );

        // Store backup metadata for listing
        $metadataPath = self::BACKUP_DIR . '/' . $backupId . '.meta.json';
        $this->disk->write($metadataPath, json_encode([
            'id' => $backup->id,
            'scope' => $scope->toArray(),
            'storage_path' => $storagePath,
            'hash' => $hash,
            'size' => $backup->size,
            'created_at' => $backup->createdAt->format('c'),
            'created_by' => $actorId,
        ], JSON_THROW_ON_ERROR));

        $this->updateIndex($backupId);

        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            $actorId,
            'cms.backup.created',
            "backup:{$backupId}",
            [
                'scope' => $scope->toArray(),
                'hash' => $hash,
                'size' => $backup->size,
            ],
        );

        return $backup;
    }

    public function restoreBackup(string $backupId, string $reason, string $actorId): RestoreResult
    {
        $metadataPath = self::BACKUP_DIR . '/' . $backupId . '.meta.json';

        if (!$this->disk->exists($metadataPath)) {
            throw CmsException::backupNotFound($backupId);
        }

        $metaJson = $this->disk->read($metadataPath);
        $meta = json_decode($metaJson, true, 512, JSON_THROW_ON_ERROR);

        $storagePath = $meta['storage_path'];
        $expectedHash = $meta['hash'];

        if (!$this->disk->exists($storagePath)) {
            throw CmsException::backupNotFound($backupId);
        }

        $json = $this->disk->read($storagePath);
        $actualHash = bin2hex(sodium_crypto_generichash($json, '', SODIUM_CRYPTO_GENERICHASH_BYTES));

        if (!hash_equals($expectedHash, $actualHash)) {
            throw CmsException::backupTampered($backupId);
        }

        $backupData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $tables = $backupData['tables'] ?? [];
        $scope = BackupScope::fromArray($backupData['scope'] ?? []);
        $restoredCounts = [];
        $warnings = [];

        $this->connection->transaction(function (ConnectionInterface $conn) use ($tables, $scope, &$restoredCounts, &$warnings): void {
            $tablesToRestore = $this->getTablesForScope($scope);

            // Truncate in reverse order (children before parents)
            $reversed = array_reverse($tablesToRestore, true);

            foreach ($reversed as $label => $table) {
                $tenantFilter = '';
                $bindings = [];

                if ($scope->tenantId !== null && $this->tableHasTenantColumn($table)) {
                    $tenantFilter = ' WHERE tenant_id = :tenant_id';
                    $bindings['tenant_id'] = $scope->tenantId;
                }

                $conn->execute("DELETE FROM {$table}{$tenantFilter}", $bindings);
            }

            // Re-insert in forward order (parents before children)
            foreach ($tablesToRestore as $label => $table) {
                $rows = $tables[$label] ?? [];

                if (!is_array($rows)) {
                    continue;
                }

                $restoredCounts[$label] = count($rows);

                foreach ($rows as $row) {
                    if (!is_array($row) || $row === []) {
                        continue;
                    }

                    $columns = array_keys($row);
                    $placeholders = array_map(static fn(string $col): string => ":{$col}", $columns);
                    $columnList = implode(', ', $columns);
                    $placeholderList = implode(', ', $placeholders);

                    $conn->execute(
                        "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholderList})",
                        $row,
                    );
                }
            }
        });

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $actorId,
            'cms.backup.restored',
            "backup:{$backupId}",
            [
                'reason' => $reason,
                'restored_counts' => $restoredCounts,
            ],
        );

        return new RestoreResult(
            restoredCounts: $restoredCounts,
            warnings: $warnings,
        );
    }

    /**
     * @return list<Backup>
     */
    public function listBackups(?string $tenantId = null): array
    {
        $backups = [];
        $candidates = $this->scanBackupMetadata();

        foreach ($candidates as $metadataPath) {
            $metaJson = $this->disk->read($metadataPath);
            $meta = json_decode($metaJson, true);

            if (!is_array($meta) || !isset($meta['id'])) {
                continue;
            }

            $scope = BackupScope::fromArray($meta['scope'] ?? []);

            if ($tenantId !== null && $scope->tenantId !== null && $scope->tenantId !== $tenantId) {
                continue;
            }

            $backups[] = new Backup(
                id: $meta['id'],
                scope: $scope,
                storagePath: $meta['storage_path'],
                hash: $meta['hash'],
                size: $meta['size'],
                createdAt: new DateTimeImmutable($meta['created_at']),
                createdBy: $meta['created_by'],
            );
        }

        return $backups;
    }

    public function deleteBackup(string $backupId, string $reason, string $actorId): void
    {
        $metadataPath = self::BACKUP_DIR . '/' . $backupId . '.meta.json';

        if (!$this->disk->exists($metadataPath)) {
            throw CmsException::backupNotFound($backupId);
        }

        $metaJson = $this->disk->read($metadataPath);
        $meta = json_decode($metaJson, true, 512, JSON_THROW_ON_ERROR);

        $storagePath = $meta['storage_path'] ?? null;

        if ($storagePath !== null && $this->disk->exists($storagePath)) {
            $this->disk->delete($storagePath);
        }

        $this->disk->delete($metadataPath);
        $this->updateIndex($backupId, remove: true);

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $actorId,
            'cms.backup.deleted',
            "backup:{$backupId}",
            [
                'reason' => $reason,
            ],
        );
    }

    /**
     * Get the CMS tables to include based on scope.
     *
     * @return array<string, string>
     */
    private function getTablesForScope(BackupScope $scope): array
    {
        $tables = [];

        if ($scope->includeTaxonomies) {
            $tables['taxonomies'] = self::CMS_TABLES['taxonomies'];
            $tables['taxonomy_translations'] = self::CMS_TABLES['taxonomy_translations'];
            $tables['taxonomy_terms'] = self::CMS_TABLES['taxonomy_terms'];
            $tables['taxonomy_term_translations'] = self::CMS_TABLES['taxonomy_term_translations'];
        }

        if ($scope->includeContent) {
            $tables['contents'] = self::CMS_TABLES['contents'];
            $tables['content_translations'] = self::CMS_TABLES['content_translations'];
            $tables['content_blocks'] = self::CMS_TABLES['content_blocks'];
            $tables['content_taxonomy_terms'] = self::CMS_TABLES['content_taxonomy_terms'];
            $tables['comments'] = self::CMS_TABLES['comments'];
        }

        if ($scope->includeMenus) {
            $tables['menus'] = self::CMS_TABLES['menus'];
            $tables['menu_translations'] = self::CMS_TABLES['menu_translations'];
            $tables['menu_items'] = self::CMS_TABLES['menu_items'];
            $tables['menu_item_translations'] = self::CMS_TABLES['menu_item_translations'];
        }

        if ($scope->includeMedia) {
            $tables['media'] = self::CMS_TABLES['media'];
            $tables['media_translations'] = self::CMS_TABLES['media_translations'];
            $tables['media_derivatives'] = self::CMS_TABLES['media_derivatives'];
        }

        if ($scope->includeSettings) {
            $tables['settings'] = self::CMS_TABLES['settings'];
            $tables['settings_history'] = self::CMS_TABLES['settings_history'];
            $tables['redirects'] = self::CMS_TABLES['redirects'];
        }

        return $tables;
    }

    /**
     * Check if a CMS table has a tenant_id column for scoping.
     */
    private function tableHasTenantColumn(string $table): bool
    {
        // Tables with tenant_id columns
        return in_array($table, [
            'cms_taxonomies',
            'cms_taxonomy_terms',
            'cms_contents',
            'cms_menus',
            'cms_media',
            'cms_redirects',
            'cms_settings',
            'cms_comments',
        ], true);
    }

    /**
     * Scan backup directory for metadata files.
     *
     * @return list<string>
     */
    private function scanBackupMetadata(): array
    {
        $paths = [];

        // Use the backup index approach — list known metadata files
        // The disk may not support directory listing, so we maintain an index
        $indexPath = self::BACKUP_DIR . '/index.json';

        if ($this->disk->exists($indexPath)) {
            $indexJson = $this->disk->read($indexPath);
            $index = json_decode($indexJson, true);

            if (is_array($index)) {
                foreach ($index as $id) {
                    $metaPath = self::BACKUP_DIR . '/' . $id . '.meta.json';

                    if ($this->disk->exists($metaPath)) {
                        $paths[] = $metaPath;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * Update the backup index file.
     */
    private function updateIndex(string $backupId, bool $remove = false): void
    {
        $indexPath = self::BACKUP_DIR . '/index.json';
        $index = [];

        if ($this->disk->exists($indexPath)) {
            $indexJson = $this->disk->read($indexPath);
            $decoded = json_decode($indexJson, true);

            if (is_array($decoded)) {
                $index = $decoded;
            }
        }

        if ($remove) {
            $index = array_values(array_filter($index, static fn(string $id): bool => $id !== $backupId));
        } else {
            $index[] = $backupId;
        }

        $this->disk->write($indexPath, json_encode($index, JSON_THROW_ON_ERROR));
    }

    private function generateId(): string
    {
        $time = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12) . bin2hex(random_bytes(1)),
        );
    }
}
