<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Represents the differences between two content revisions.
 */
#[Api(since: '1.0.0')]
final readonly class RevisionDiff
{
    /**
     * @param ContentRevision $from The older revision
     * @param ContentRevision $to The newer revision
     * @param array<string, array{old: string|null, new: string|null}> $changes Map of field name to old/new values
     */
    public function __construct(
        public ContentRevision $from,
        public ContentRevision $to,
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
