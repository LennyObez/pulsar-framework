<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Ticket category: organizes tickets into a hierarchical taxonomy.
 */
#[Api(since: '1.0.0')]
final readonly class TicketCategory
{
    /**
     * @param string $id UUIDv7
     * @param string $name Display name
     * @param string $slug URL-safe identifier
     * @param string|null $description Category description
     * @param string|null $parentId Self-referencing FK for nesting
     * @param int $sortOrder Sibling ordering within parent
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable $updatedAt Auto-managed update timestamp
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $parentId,
        public int $sortOrder,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a new category.
     */
    public static function create(
        string $id,
        string $name,
        string $slug,
        ?string $description = null,
        ?string $parentId = null,
        int $sortOrder = 0,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            name: $name,
            slug: $slug,
            description: $description,
            parentId: $parentId,
            sortOrder: $sortOrder,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Rename the category.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function rename(string $name, string $slug): self
    {
        return clone($this, [
            'name' => $name,
            'slug' => $slug,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Update the description.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function describe(?string $description): self
    {
        return clone($this, [
            'description' => $description,
            'updatedAt' => new DateTimeImmutable(),
        ]);
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
}
