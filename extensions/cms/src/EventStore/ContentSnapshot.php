<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\EventStore;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Atomic snapshot of ALL translations and blocks for a content item.
 *
 * Used in governance-grade environments to prove the exact state of
 * all translations at the moment of publication. Any modification
 * is detected via the evidence hash on restore.
 *
 * @psalm-api Public DTO returned from ContentSnapshotServiceInterface;
 *            consumed by governance / audit views.
 */
#[Api(since: '1.0.0')]
final readonly class ContentSnapshot
{
    /**
     * @param string $id UUIDv7
     * @param string $contentId UUIDv7 FK content
     * @param int $snapshotNumber Auto-increment per content_id
     * @param list<array<string, mixed>> $translationsJson Complete serialization of all ContentTranslation records
     * @param list<array<string, mixed>> $blocksJson Complete serialization of all ContentBlock records per locale
     * @param list<string> $taxonomyTermIds Array of attached term UUIDv7s
     * @param string $evidenceHash BLAKE2b hash of entire snapshot payload
     * @param string $reason Why the snapshot was taken
     * @param string $createdBy UUIDv7 user who created the snapshot
     * @param DateTimeImmutable $createdAt When the snapshot was created
     */
    public function __construct(
        public string $id,
        public string $contentId,
        public int $snapshotNumber,
        public array $translationsJson,
        public array $blocksJson,
        public array $taxonomyTermIds,
        public string $evidenceHash,
        public string $reason,
        public string $createdBy,
        public DateTimeImmutable $createdAt,
    ) {}
}
