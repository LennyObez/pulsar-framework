<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Repository interface for the Product aggregate root.
 * @api
 */
#[Api(since: '1.0.0')]
interface ProductRepositoryInterface
{
    public function findById(string $id): ?Product;

    /**
     * @param list<string> $ids
     * @return array<string, Product> Keyed by product ID
     */
    public function findByIds(array $ids): array;

    public function findBySku(string $sku, ?string $tenantId = null): ?Product;

    /**
     * Find a product linked to a given CMS content ID.
     */
    public function findByContentId(string $contentId): ?Product;

    /**
     * @param array<string, mixed> $filters Filtering criteria (status, tenantId, digital, etc.)
     * @return list<Product>
     */
    public function listProducts(array $filters, int $page, int $perPage): array;

    public function save(Product $product): void;

    /**
     * Atomically reserve stock for a product. Returns false if insufficient stock.
     */
    public function reserveStock(string $productId, int $quantity): bool;

    /**
     * Restore previously reserved stock for a product.
     */
    public function restoreStock(string $productId, int $quantity): void;
}
