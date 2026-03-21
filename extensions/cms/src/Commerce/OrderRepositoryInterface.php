<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Repository interface for the Order aggregate root.
 * @api
 */
#[Api(since: '1.0.0')]
interface OrderRepositoryInterface
{
    public function findById(string $id): ?Order;

    public function findByNumber(string $orderNumber, ?string $tenantId = null): ?Order;

    /**
     * @param array<string, mixed> $filters Filtering criteria (status, customerId, tenantId, etc.)
     * @return list<Order>
     */
    public function listOrders(array $filters, int $page, int $perPage): array;

    public function save(Order $order): void;

    public function updateStatus(string $id, OrderStatus $status): void;

    /**
     * Find all orders for a customer, newest first.
     *
     * @return list<Order>
     */
    public function findByCustomer(string $customerId, int $page = 1, int $perPage = 20): array;
}
