<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Taxonomy;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A taxonomy definition (e.g., "category", "tag", or custom).
 *
 * Hierarchical taxonomies support parent-child term relationships (categories);
 * flat taxonomies do not (tags).
 *
 * @psalm-api Public DTO returned from TaxonomyRepositoryInterface; consumed
 *            by content services and admin templates.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Taxonomy
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $slug URL-safe identifier, unique per tenant
     * @param bool $hierarchical Whether terms support parent-child relationships
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param string|null $importId Stable import identifier for idempotent imports
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $slug,
        public bool $hierarchical,
        public DateTimeImmutable $createdAt,
        public ?string $importId = null,
    ) {}
}
