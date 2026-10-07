<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for customer account persistence.
 * @api
 */
#[Api(since: '1.0.0')]
interface CustomerRepositoryInterface
{
    public function findById(string $id): ?Customer;

    public function findByEmail(string $email, ?string $tenantId = null): ?Customer;

    public function findByUserId(string $userId): ?Customer;

    /**
     * List customers with optional search and pagination.
     *
     * @return PaginationResult<Customer>
     */
    public function listCustomers(
        ?string $tenantId = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult;

    public function save(Customer $customer): void;
}
