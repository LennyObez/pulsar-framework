<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Tag;

use Pulsar\Api\Api;

/**
 * Tag invalidation strategy contract.
 */
#[Api(since: '1.0.0')]
interface TagStrategyInterface
{
    /**
     * Get current version numbers for the given tags.
     *
     * @param list<string> $tags
     *
     * @return array<string, string> Tag name => version string
     */
    public function getTagVersions(array $tags): array;

    /**
     * Invalidate a single tag (bump its version).
     */
    public function invalidateTag(string $tag): void;

    /**
     * Invalidate multiple tags.
     *
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): void;
}
