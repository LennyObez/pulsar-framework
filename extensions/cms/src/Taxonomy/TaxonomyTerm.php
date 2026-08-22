<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Taxonomy;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A single term within a taxonomy.
 *
 * Terms in hierarchical taxonomies may have a parent term.
 * Sort order determines display position among siblings.
 *
 * @psalm-api Public DTO returned from TaxonomyRepositoryInterface; consumed
 *            by content services and admin templates.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TaxonomyTerm
{
    /**
     * @param string $id UUIDv7
     * @param string $taxonomyId UUIDv7 FK taxonomies
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string|null $parentId UUIDv7 self-referential (hierarchical only)
     * @param int $sortOrder Position among siblings
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param string|null $importId Stable import identifier for idempotent imports
     */
    public function __construct(
        public string $id,
        public string $taxonomyId,
        public ?string $tenantId,
        public ?string $parentId,
        public int $sortOrder,
        public DateTimeImmutable $createdAt,
        public ?string $importId = null,
    ) {}

    /**
     * Return a copy of this term re-parented under the given parent term,
     * or detached to the taxonomy root when $parentId is null.
     *
     * @param string|null $parentId UUIDv7 of the new parent term, or null to detach
     */
    public function withParent(?string $parentId): self
    {
        return clone($this, ['parentId' => $parentId]);
    }
}
