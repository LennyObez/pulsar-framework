<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Metadata record for a CMS backup.
 *
 * @psalm-api Public DTO returned from BackupServiceInterface; consumed by
 *            admin backup-management views.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Backup
{
    /**
     * @param string $id UUIDv7
     * @param BackupScope $scope What data categories are included
     * @param string $storagePath Relative path to the backup file in private storage
     * @param string $hash BLAKE2b hash of the backup file for integrity verification
     * @param int $size Backup file size in bytes
     * @param DateTimeImmutable $createdAt When the backup was created
     * @param string $createdBy UUIDv7 of the actor who initiated the backup
     */
    public function __construct(
        public string $id,
        public BackupScope $scope,
        public string $storagePath,
        public string $hash,
        public int $size,
        public DateTimeImmutable $createdAt,
        public string $createdBy,
    ) {}
}
