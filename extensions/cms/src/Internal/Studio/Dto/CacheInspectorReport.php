<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Studio\Dto;

use Pulsar\Api\Internal;

/**
 * Aggregated report from the content cache inspector panel.
 */
#[Internal]
final readonly class CacheInspectorReport
{
    /**
     * @param list<CacheInspectorEntry> $entries
     */
    public function __construct(
        public array $entries,
        public float $hitRate,
        public int $totalHits,
        public int $totalMisses,
    ) {}
}
