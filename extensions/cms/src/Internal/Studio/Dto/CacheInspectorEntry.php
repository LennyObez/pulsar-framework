<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Studio\Dto;

use Pulsar\Api\Internal;

/**
 * Row in the content cache inspector panel: a single cached page entry.
 *
 * @psalm-api Constructed by ContentCacheInspectorPanel; consumed by Studio templates.
 */
#[Internal]
final readonly class CacheInspectorEntry
{
    public function __construct(
        public string $cacheKey,
        public string $contentId,
        public bool $isHit,
    ) {}
}
