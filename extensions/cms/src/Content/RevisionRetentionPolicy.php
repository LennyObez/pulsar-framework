<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configures how old content revisions are retained and pruned.
 *
 * Used by RevisionRetentionJob to determine which revisions to keep
 * and which to delete. Supports both count-based and age-based retention.
 */
#[Api(since: '1.0.0')]
final readonly class RevisionRetentionPolicy
{
    /**
     * @param int $maxRevisionsPerContent Maximum revisions to keep per content item (0 = unlimited)
     * @param int $maxAgeDays Delete revisions older than this many days (0 = unlimited)
     * @param bool $keepPublished Never delete revisions that were published
     * @param bool $keepFirstRevision Always keep the first revision of each content item
     */
    public function __construct(
        public int $maxRevisionsPerContent = 50,
        public int $maxAgeDays = 365,
        public bool $keepPublished = true,
        public bool $keepFirstRevision = true,
    ) {
        if ($maxRevisionsPerContent < 0 || $maxAgeDays < 0) {
            throw new InvalidArgumentException('Retention policy values must be non-negative');
        }
    }

    /**
     * Build from a raw config array.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            maxRevisionsPerContent: (int) ($data['max_revisions_per_content'] ?? 50),
            maxAgeDays: (int) ($data['max_age_days'] ?? 365),
            keepPublished: (bool) ($data['keep_published'] ?? true),
            keepFirstRevision: (bool) ($data['keep_first_revision'] ?? true),
        );
    }
}
