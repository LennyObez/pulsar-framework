<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Pulsar\Resilience\Backup\ArchiveSeal;
use Pulsar\Resilience\Backup\BackupManifest;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\Backup\BackupVerification;
use Pulsar\Resilience\Backup\RestoreReport;
use Pulsar\Resilience\Backup\SealedArchiveBackupService;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function file_get_contents;
use function is_file;
use function random_bytes;
use function strlen;

/**
 * A backup service that seals properly and then believes whatever it is handed.
 *
 * The confidentiality half is real: it delegates to the shipped driver, so the
 * archive on disk is genuinely unreadable. Only {@see verify()} is broken, and it
 * is broken in the way an implementation drifts into — reporting intact whenever
 * the file is there and is not empty, which every archive is, including one
 * somebody edited.
 *
 * A deployment running this can be handed an archive an attacker chose the
 * contents of, during a recovery, and will report it sound.
 */
final class CredulousBackupService implements BackupServiceInterface
{
    private readonly SealedArchiveBackupService $inner;

    public function __construct()
    {
        $this->inner = new SealedArchiveBackupService(new ArchiveSeal(MasterKey::fromHex(bin2hex(random_bytes(32)))));
    }

    #[Override]
    public function backUp(string $archivePath, iterable $sources): BackupManifest
    {
        return $this->inner->backUp($archivePath, $sources);
    }

    #[Override]
    public function verify(string $archivePath): BackupVerification
    {
        if (!is_file($archivePath)) {
            return BackupVerification::refused($archivePath, 'no such file');
        }

        return BackupVerification::readBack(
            $archivePath,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->inner->keyId(),
            [],
            strlen((string) file_get_contents($archivePath)),
        );
    }

    #[Override]
    public function restore(string $archivePath, array $targets): RestoreReport
    {
        return $this->inner->restore($archivePath, $targets);
    }

    #[Override]
    public function keyId(): string
    {
        return $this->inner->keyId();
    }
}
