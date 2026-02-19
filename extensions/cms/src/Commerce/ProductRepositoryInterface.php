<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Repository interface for the Product aggregate root.
 */
#[Api(since: '1.0.0')]
interface ProductRepositoryInterface
{
    public function findById(string $id): ?Product;

    public function findBySku(string $sku, ?string $tenantId = null): ?Product;

    /**
     * @param array<string, mixed> $filters Filtering criteria (status, tenantId, digital, etc.)
     * @return list<Product>
     */
    public function listProducts(array $filters, int $page, int $perPage): array;

    public function save(Product $product): void;
}
