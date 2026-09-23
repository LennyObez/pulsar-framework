<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use Pulsar\Api\Api;

/**
 * One entry that came back out of an archive, and where it went.
 *
 * The target id is recorded rather than inferred, because the same archive
 * restored on two hosts can legitimately land in different places — a scratch
 * database on the drill host, the live one on the recovery host — and a report
 * that only says "restored" cannot be read afterwards to establish which.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class RestoredEntry
{
    /**
     * @param non-empty-string $name     Entry name as it stood in the archive
     * @param non-empty-string $targetId The target that accepted it
     * @param int<0, max>      $bytes    Bytes the target consumed
     */
    public function __construct(
        public string $name,
        public string $targetId,
        public int $bytes,
    ) {}
}
