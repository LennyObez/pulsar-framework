<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Category;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Forum category: organizes threads into a hierarchical taxonomy.
 *
 * Supports nesting via parentId and ordering via sortOrder.
 * Thread creation can be disabled per category via isLocked.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Category
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string|null $parentId UUIDv7 self-ref for nested categories
     * @param string $slug URL-safe identifier
     * @param int $sortOrder Sibling ordering within parent
     * @param bool $isLocked When true, no new threads can be created
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable $updatedAt Auto-managed update timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public ?string $parentId,
        public string $slug,
        public int $sortOrder,
        public bool $isLocked,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a new unlocked category.
     */
    public static function create(
        string $id,
        string $slug,
        ?string $tenantId = null,
        ?string $parentId = null,
        int $sortOrder = 0,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            parentId: $parentId,
            slug: $slug,
            sortOrder: $sortOrder,
            isLocked: false,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Move category under a different parent.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function reparent(?string $parentId): self
    {
        return clone($this, [
            'parentId' => $parentId,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Update the sibling sort order.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function reorder(int $sortOrder): self
    {
        return clone($this, [
            'sortOrder' => $sortOrder,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Lock the category to prevent new thread creation.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function lock(): self
    {
        return clone($this, [
            'isLocked' => true,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Unlock the category to allow new thread creation.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function unlock(): self
    {
        return clone($this, [
            'isLocked' => false,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Update the URL slug.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function changeSlug(string $slug): self
    {
        return clone($this, [
            'slug' => $slug,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }
}
