<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Repository interface for customer account persistence.
 */
#[Api(since: '1.0.0')]
interface CustomerRepositoryInterface
{
    public function findById(string $id): ?Customer;

    public function findByEmail(string $email, ?string $tenantId = null): ?Customer;

    public function findByUserId(string $userId): ?Customer;

    public function save(Customer $customer): void;
}
