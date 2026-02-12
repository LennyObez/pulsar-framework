<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Category;

use Pulsar\Api\Api;

/**
 * Repository interface for forum categories.
 */
#[Api(since: '1.0.0')]
interface CategoryRepositoryInterface
{
    public function findById(string $id): ?Category;

    /**
     * Find a category by its URL slug.
     */
    public function findBySlug(string $slug, ?string $tenantId = null): ?Category;

    /**
     * Find all top-level categories (no parent), ordered by sortOrder.
     *
     * @return list<Category>
     */
    public function findRoots(?string $tenantId = null): array;

    /**
     * Find all child categories of a given parent, ordered by sortOrder.
     *
     * @return list<Category>
     */
    public function findByParent(string $parentId): array;

    public function save(Category $category): void;

    public function delete(Category $category): void;
}
