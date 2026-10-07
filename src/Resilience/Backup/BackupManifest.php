<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function array_sum;
use function count;
use function str_starts_with;

/**
 * What a completed backup actually put in the archive.
 *
 * Returned by {@see BackupServiceInterface::backUp()} and printed by
 * `pulsar backup:run`. It is a record of what happened, not a plan of what was
 * meant to happen: the entry list is built while the archive is written, from the
 * entries that were written, so a source that yielded nothing shows up as a
 * source with no entries rather than as a line the operator has to go and check.
 *
 * That distinction is the reason `backup:run` prints this instead of the
 * configured coverage. An operator reading "database, audit, files" learns what
 * the deployment INTENDED to back up. An operator reading "database/orders.ndjson
 * 4.2 MB, audit/audit.log 118 KB" learns what is in the file they are holding,
 * and can see for themselves that the audit trail is in it.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class BackupManifest
{
    /**
     * @param non-empty-string    $archivePath Where the sealed archive was written
     * @param non-empty-string    $keyId       Identifier of the archive key it was sealed under
     * @param list<ArchivedEntry> $entries     Every entry written, in archive order
     * @param list<string>        $sourceIds   The sources that contributed, in the order they ran
     * @param int<0, max>         $sealedBytes Size of the archive on disk, seal included
     */
    public function __construct(
        public string $archivePath,
        public DateTimeImmutable $createdAt,
        public string $keyId,
        public array $entries,
        public array $sourceIds,
        public int $sealedBytes,
    ) {}

    /** Total content length across every entry, before sealing. */
    #[NoDiscard]
    public function contentBytes(): int
    {
        return (int) array_sum(array_map(static fn(ArchivedEntry $e): int => $e->bytes, $this->entries));
    }

    #[NoDiscard]
    public function entryCount(): int
    {
        return count($this->entries);
    }

    /**
     * Whether the archive holds an entry contributed by the named source.
     *
     * The question an operator asks about the audit trail, and the reason source
     * ids are the first path segment of every entry name.
     */
    #[NoDiscard]
    public function covers(string $sourceId): bool
    {
        foreach ($this->entries as $entry) {
            if (str_starts_with($entry->name, $sourceId . '/')) {
                return true;
            }
        }

        return false;
    }
}
