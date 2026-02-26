<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

/**
 * Service interface for CMS backup and restore operations.
 *
 * Backups are stored as JSON files with BLAKE2b integrity hashes.
 * Restore operations validate hash integrity before applying data.
 */
#[Api(since: '1.0.0')]
interface BackupServiceInterface
{
    /**
     * Create a backup of CMS data according to the given scope.
     */
    public function createBackup(BackupScope $scope, string $actorId): Backup;

    /**
     * Restore CMS data from a backup, replacing current data.
     *
     * Validates the backup hash for tamper detection before applying.
     *
     * @throws \Pulsar\Extension\Cms\Exception\CmsException If the backup is not found or hash is invalid
     */
    public function restoreBackup(string $backupId, string $reason, string $actorId): RestoreResult;

    /**
     * List all available backups, optionally filtered by tenant.
     *
     * @return list<Backup>
     */
    public function listBackups(?string $tenantId = null): array;

    /**
     * Delete a backup file and its metadata.
     *
     * @throws \Pulsar\Extension\Cms\Exception\CmsException If the backup is not found
     */
    public function deleteBackup(string $backupId, string $reason, string $actorId): void;
}
