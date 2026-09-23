<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Pulsar\Resilience\Backup\ArchivedEntry;
use Pulsar\Resilience\Backup\BackupManifest;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\Backup\BackupVerification;
use Pulsar\Resilience\Backup\RestoredEntry;
use Pulsar\Resilience\Backup\RestoreReport;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function sodium_crypto_generichash;
use function strlen;

/**
 * A backup service that writes a plain tarball, and satisfies every signature.
 *
 * It binds. It constructs. `backup_primitive_resolved` names it exactly as it
 * names {@see \Pulsar\Resilience\Backup\SealedArchiveBackupService}. It round-trips
 * its own output perfectly, so a check that backed something up and restored it
 * would pass. The one thing it does not do is seal, which means anybody who can
 * read the file can read every row, every audit record and every uploaded
 * document the deployment holds — and an archive exists to leave the host.
 */
final class UnsealedBackupService implements BackupServiceInterface
{
    /** @var array<non-empty-string, string> */
    private array $written = [];

    #[Override]
    public function backUp(string $archivePath, iterable $sources): BackupManifest
    {
        $this->written = [];
        $entries = [];
        $body = '';

        foreach ($sources as $source) {
            foreach ($source->entries() as $entry) {
                $content = '';

                foreach ($entry->chunks as $chunk) {
                    $content .= $chunk;
                }

                $name = $source->id() . '/' . $entry->name;
                $this->written[$name] = $content;
                $body .= $name . "\n" . $content . "\n";

                $entries[] = new ArchivedEntry(
                    $name,
                    strlen($content),
                    bin2hex(sodium_crypto_generichash($content, '', 32)),
                );
            }
        }

        file_put_contents($archivePath, $body);

        return new BackupManifest(
            archivePath: $archivePath,
            createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            keyId: 'unsealed',
            entries: $entries,
            sourceIds: ['probe'],
            sealedBytes: strlen($body),
        );
    }

    #[Override]
    public function verify(string $archivePath): BackupVerification
    {
        if (!is_file($archivePath)) {
            return BackupVerification::refused($archivePath, 'no such file');
        }

        $bytes = (string) file_get_contents($archivePath);

        // No seal, so the only integrity claim available is "the bytes are the ones
        // I wrote" -- which is true right up until somebody edits them.
        return $bytes === ''
            ? BackupVerification::refused($archivePath, 'the archive is empty')
            : BackupVerification::readBack(
                $archivePath,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                'unsealed',
                [],
                strlen($bytes),
            );
    }

    #[Override]
    public function restore(string $archivePath, array $targets): RestoreReport
    {
        $restored = [];

        foreach ($this->written as $name => $content) {
            foreach ($targets as $target) {
                if (!$target->accepts($name)) {
                    continue;
                }

                $restored[] = new RestoredEntry($name, $target->id(), $target->restore($name, [$content]));

                break;
            }
        }

        return new RestoreReport($archivePath, $restored, []);
    }

    #[Override]
    public function keyId(): string
    {
        return 'unsealed';
    }
}
