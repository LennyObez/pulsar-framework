<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Data;

use Pulsar\Api\Api;

/**
 * Metadata contributed by a single MetadataContributorInterface implementation.
 *
 * Each contributor produces named sections of key-value data. The size in bytes
 * is tracked to enforce per-contributor resource limits.
 */
#[Api(since: '1.0.0')]
final readonly class ContributedMetadata
{
    /**
     * @param array<string, array<string, mixed>> $sections
     */
    public function __construct(
        public string $contributorId,
        public array $sections,
        private int $sizeBytes,
    ) {}

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    /**
     * @return array{contributor_id: string, sections: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'contributor_id' => $this->contributorId,
            'sections' => $this->sections,
        ];
    }
}
