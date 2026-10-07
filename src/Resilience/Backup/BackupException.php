<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Every way taking, verifying or restoring a sealed archive can refuse.
 *
 * One class rather than a hierarchy, because a caller has exactly two useful
 * reactions — report it, or fail the run — and the message is what an operator
 * acts on. What matters is that each factory names a DIFFERENT refusal: an
 * archive that will not open because the key is wrong is not the same event as
 * one that will not open because a byte was changed, and an operator whose
 * restore drill fails needs the report to tell those apart before they reach for
 * the other copy.
 *
 * Nothing here is thrown to signal "the backup is empty" or "no source is
 * configured". Those are FACTS about the deployment that
 * {@see BackupManifest} and {@see BackupPlan} carry, and turning them into
 * exceptions would hide them from the report that has to state them.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class BackupException extends RuntimeException
{
    /**
     * The destination could not be opened for writing, so nothing was backed up.
     */
    #[NoDiscard]
    public static function destinationUnwritable(string $path, string $reason): self
    {
        return new self(sprintf('Backup destination "%s" cannot be written: %s.', $path, $reason));
    }

    /**
     * There is already a file where the archive would go, and it was left alone.
     *
     * A SEPARATE REFUSAL FROM {@see destinationUnwritable()} because the remedy is
     * the opposite one: an unwritable destination is a permissions problem to fix,
     * and an occupied one is an archive that already exists and must not be
     * destroyed. Every other writer in this module refuses to overwrite what it
     * finds -- {@see FileTreeRestoreTarget} refuses an existing file,
     * {@see DatabaseRestoreTarget} refuses a populated table -- and the destination
     * of a backup is the one file in the set whose loss cannot be recovered from,
     * because the thing that would have recovered it is what overwrote it.
     */
    #[NoDiscard]
    public static function destinationOccupied(string $path): self
    {
        return new self(sprintf(
            'Backup destination "%s" already holds a file, and it was left untouched: writing '
                . 'over it would destroy the archive it holds. Back up to another path, or remove '
                . 'that file once you have established it is not a backup you still need.',
            $path,
        ));
    }

    /**
     * The archive could not be opened for reading.
     */
    #[NoDiscard]
    public static function archiveUnreadable(string $path, string $reason): self
    {
        return new self(sprintf('Backup archive "%s" cannot be read: %s.', $path, $reason));
    }

    /**
     * The file is not a Pulsar archive at all, or is a format this release cannot read.
     */
    #[NoDiscard]
    public static function notAnArchive(string $path, string $reason): self
    {
        return new self(sprintf('"%s" is not a readable Pulsar backup archive: %s.', $path, $reason));
    }

    /**
     * The archive was sealed under a different key than the one this deployment holds.
     *
     * Distinct from {@see sealBroken()} on purpose. Both mean "this archive will
     * not open here", and the remedy is opposite: a key mismatch is fixed by
     * restoring with the key the archive was sealed under (or by
     * `PULSAR_MASTER_KEY_PREVIOUS` during a rotation window), while a broken seal
     * means the bytes changed and the copy must be discarded.
     */
    #[NoDiscard]
    public static function sealedUnderAnotherKey(string $path, string $archiveKeyId, string $deploymentKeyId): self
    {
        return new self(sprintf(
            'Backup archive "%s" was sealed under archive key %s and this deployment derives %s; '
                . 'restore it where that master key is held, or set PULSAR_MASTER_KEY_PREVIOUS if '
                . 'the key has been rotated since the archive was taken.',
            $path,
            $archiveKeyId,
            $deploymentKeyId,
        ));
    }

    /**
     * A chunk failed authentication: the archive has been altered or truncated.
     */
    #[NoDiscard]
    public static function sealBroken(string $path, int $chunk): self
    {
        return new self(sprintf(
            'Backup archive "%s" failed authentication at sealed chunk %d: the archive has been '
                . 'altered or corrupted since it was written and MUST NOT be restored.',
            $path,
            $chunk,
        ));
    }

    /**
     * The stream ended without the final tag, so the archive is incomplete.
     *
     * A separate refusal from {@see sealBroken()} because every individual chunk
     * can authenticate while the archive is still a prefix of the one that was
     * written — an interrupted upload, a truncating filesystem, a partial copy.
     * Restoring a prefix silently loses whatever came after the cut.
     */
    #[NoDiscard]
    public static function archiveTruncated(string $path): self
    {
        return new self(sprintf(
            'Backup archive "%s" ends without its final authentication tag, so it is a truncated '
                . 'copy of the archive that was written and MUST NOT be restored.',
            $path,
        ));
    }

    /**
     * The sealed payload authenticated but does not parse into entries.
     */
    #[NoDiscard]
    public static function malformedPayload(string $path, string $reason): self
    {
        return new self(sprintf('Backup archive "%s" holds a malformed entry stream: %s.', $path, $reason));
    }

    /**
     * An entry's content digest does not match the one recorded when it was written.
     */
    #[NoDiscard]
    public static function entryDigestMismatch(string $path, string $entry): self
    {
        return new self(sprintf(
            'Entry "%s" in backup archive "%s" does not match the digest recorded when it was '
                . 'written, so its content changed between backup and restore.',
            $entry,
            $path,
        ));
    }

    /**
     * A source could not be read while the archive was being written.
     */
    #[NoDiscard]
    public static function sourceUnreadable(string $source, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Backup source "%s" could not be read: %s.', $source, $reason),
            previous: $previous,
        );
    }

    /**
     * A restore target refused, or failed to write, an entry it had accepted.
     */
    #[NoDiscard]
    public static function restoreFailed(string $entry, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Restoring entry "%s" failed: %s.', $entry, $reason),
            previous: $previous,
        );
    }

    /**
     * An entry name in the archive is not one this release will hand to a target.
     *
     * Archives are decrypted with a key the deployment holds, so an entry name is
     * not untrusted in the ordinary sense; it is still the one field in the format
     * that becomes a filesystem path in {@see FileTreeRestoreTarget}, and a name
     * carrying `..`, an absolute prefix or a NUL byte is refused here rather than
     * at the target, so every target inherits the refusal.
     */
    #[NoDiscard]
    public static function unsafeEntryName(string $entry): self
    {
        return new self(sprintf(
            'Backup archive holds entry name "%s", which is not a relative, traversal-free path; '
                . 'the archive is refused rather than restored.',
            $entry,
        ));
    }
}
