<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Repository interface for product variant persistence and stock management.
 * @api
 */
#[Api(since: '1.0.0')]
interface ProductVariantRepositoryInterface
{
    public function findById(string $id): ?ProductVariant;

    /**
     * @param list<string> $ids
     * @return array<string, ProductVariant> Keyed by variant ID
     */
    public function findByIds(array $ids): array;

    /**
     * @return list<ProductVariant>
     */
    public function findByProductId(string $productId): array;

    public function save(ProductVariant $variant): void;

    /**
     * Atomically reserve stock for a variant. Returns false if insufficient stock.
     */
    public function reserveStock(string $variantId, int $quantity): bool;

    /**
     * Restore previously reserved stock for a variant.
     */
    public function restoreStock(string $variantId, int $quantity): void;
}
