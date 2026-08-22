<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Collaboration;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable CRDT document state for a content item.
 *
 * The state vector is stored as base64-encoded binary. The actual CRDT merge
 * happens client-side in Yjs; the server stores the latest full snapshot.
 *
 * @psalm-api Public DTO returned from CollaborationRepositoryInterface; consumed
 *            by CollaborationService and the Yjs sync endpoints.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CrdtDocument
{
    public function __construct(
        public string $contentId,
        public string $stateVector,
        public int $version,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create an initial empty document for a content item.
     */
    public static function initial(string $contentId): self
    {
        return new self(
            contentId: $contentId,
            stateVector: '',
            version: 1,
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Apply a CRDT state update, producing a new document with incremented version.
     */
    public function applyUpdate(string $newState): self
    {
        return new self(
            contentId: $this->contentId,
            stateVector: $newState,
            version: $this->version + 1,
            updatedAt: new DateTimeImmutable(),
        );
    }
}
