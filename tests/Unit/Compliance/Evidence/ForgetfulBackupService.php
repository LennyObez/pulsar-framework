<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use Override;
use Pulsar\Resilience\Backup\ArchiveSeal;
use Pulsar\Resilience\Backup\BackupManifest;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\Backup\BackupVerification;
use Pulsar\Resilience\Backup\RestoreReport;
use Pulsar\Resilience\Backup\SealedArchiveBackupService;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function random_bytes;

/**
 * A backup service whose archives seal, verify, and give nothing back.
 *
 * The failure a verification sweep cannot see. Every archive it writes is
 * confidential and tamper-evident, `pulsar backup:verify` reports them all intact,
 * and the deployment's evidence looks complete right up to the recovery — where
 * the restore claims to have run and puts nothing anywhere. This is why the round
 * trip compares the bytes that came back with the bytes that went in instead of
 * counting restored entries.
 */
final class ForgetfulBackupService implements BackupServiceInterface
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
        return $this->inner->verify($archivePath);
    }

    #[Override]
    public function restore(string $archivePath, array $targets): RestoreReport
    {
        return new RestoreReport($archivePath, [], []);
    }

    #[Override]
    public function keyId(): string
    {
        return $this->inner->keyId();
    }
}
