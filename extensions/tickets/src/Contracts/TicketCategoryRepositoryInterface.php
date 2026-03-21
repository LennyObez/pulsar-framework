<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Tickets\Domain\TicketCategory;

/**
 * Repository interface for ticket categories.
 * @api
 */
#[Api(since: '1.0.0')]
interface TicketCategoryRepositoryInterface
{
    public function findById(string $id): ?TicketCategory;

    public function findBySlug(string $slug): ?TicketCategory;

    /**
     * Find all categories ordered by sortOrder.
     *
     * @return list<TicketCategory>
     */
    public function findAll(): array;

    /**
     * Find child categories of a parent.
     *
     * @return list<TicketCategory>
     */
    public function findByParent(?string $parentId): array;

    public function save(TicketCategory $category): void;

    public function delete(TicketCategory $category): void;
}
