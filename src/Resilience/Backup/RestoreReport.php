<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use NoDiscard;
use Pulsar\Api\Api;

use function count;

/**
 * What a restore put back, and what it did not.
 *
 * The second half is the load-bearing one. A restore run against the wrong set of
 * targets — a file-tree target and no database target, say — succeeds at every
 * step it performs and leaves the deployment without its rows, and a report that
 * only counted successes would read identically to a complete recovery. So every
 * entry the archive held that NO target claimed is named in {@see $skipped}, and
 * `pulsar backup:restore` exits non-zero when that list is not empty unless the
 * operator asked for a partial restore explicitly.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class RestoreReport
{
    /**
     * @param non-empty-string    $archivePath
     * @param list<RestoredEntry> $restored Entries a target accepted and wrote back
     * @param list<string>        $skipped  Entry names no target claimed
     */
    public function __construct(
        public string $archivePath,
        public array $restored,
        public array $skipped,
    ) {}

    #[NoDiscard]
    public function restoredCount(): int
    {
        return count($this->restored);
    }

    /** Whether every entry in the archive found a target. */
    #[NoDiscard]
    public function complete(): bool
    {
        return $this->skipped === [];
    }
}
