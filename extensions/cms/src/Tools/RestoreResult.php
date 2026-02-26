<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

/**
 * Result of restoring a CMS backup.
 */
#[Api(since: '1.0.0')]
final readonly class RestoreResult
{
    /**
     * @param array<string, int> $restoredCounts Number of restored records keyed by entity type
     * @param list<string> $warnings Non-fatal issues encountered during restore
     */
    public function __construct(
        public array $restoredCounts,
        public array $warnings,
    ) {}
}
