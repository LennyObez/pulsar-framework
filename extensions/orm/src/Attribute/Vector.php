<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Maps an entity property to a vector/embedding column.
 *
 * Supported databases:
 * - PostgreSQL: pgvector extension (VECTOR type)
 * - MySQL 9.0+: native VECTOR type
 * - SQLite: sqlite-vec extension (float[N])
 *
 * Usage:
 *     #[Vector(dimensions: 1536)]
 *     public array $embedding;
 *
 * @psalm-api PHP attribute consumed via reflection during ORM hydration.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class Vector
{
    /**
     * @param int $dimensions Number of dimensions in the embedding vector
     * @param string|null $name Column name override; null = property name
     * @param string $distanceMetric Default distance metric: 'cosine', 'l2', 'inner_product'
     */
    public function __construct(
        public int $dimensions = 1536,
        public ?string $name = null,
        public string $distanceMetric = 'cosine',
    ) {}
}
