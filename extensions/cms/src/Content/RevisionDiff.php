<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Represents the diff between two content revisions.
 */
#[Api(since: '1.0.0')]
final readonly class RevisionDiff
{
    /**
     * @param string $fromRevisionId Source revision
     * @param string $toRevisionId Target revision
     * @param list<FieldDiff> $changes List of changed fields
     */
    public function __construct(
        public string $fromRevisionId,
        public string $toRevisionId,
        public array $changes,
    ) {}

    /**
     * Whether any fields differ between the two revisions.
     */
    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }
}
