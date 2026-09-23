<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use Pulsar\Api\Api;

/**
 * Something that puts an archive entry back.
 *
 * The inverse of {@see BackupSourceInterface}, and separate from it on purpose.
 * A source that could also restore would be one object holding both halves of the
 * round trip, and every test of it would compare an implementation with itself;
 * more importantly, a deployment restores into a DIFFERENT place than it backed
 * up from more often than not — a standby host, a scratch database, a directory
 * an operator is about to inspect — and that is expressible only when the target
 * is chosen independently of the source.
 *
 * {@see accepts()} is what routes an entry, and a target that accepts nothing in
 * the archive is not an error: {@see RestoreReport} counts the entries no target
 * claimed and names them, so a restore run with the wrong target set reports what
 * it skipped instead of appearing to succeed.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface RestoreTargetInterface
{
    /**
     * Stable identifier, reported per entry in {@see RestoreReport}.
     *
     * @return non-empty-string
     */
    public function id(): string;

    /**
     * Whether this target handles the named entry.
     */
    public function accepts(string $entryName): bool;

    /**
     * Write the entry back, and return the number of bytes it consumed.
     *
     * $chunks is traversable once and arrives in archive order. An implementation
     * MUST consume it fully or throw: leaving it partially read leaves the
     * archive reader mid-entry.
     *
     * A target reports what it CONSUMED, never what it was promised, and the count
     * cannot be negative: {@see SealedArchiveBackupService} compares the returned
     * figure against the archive's own per-entry byte count and refuses the restore
     * when they differ, so a target that guessed would turn a good archive into a
     * refused one and a target that under-reported would hide a short write.
     *
     * @param iterable<string> $chunks
     *
     * @return int<0, max> Bytes the target consumed
     *
     * @throws BackupException when the entry cannot be written
     */
    public function restore(string $entryName, iterable $chunks): int;
}
