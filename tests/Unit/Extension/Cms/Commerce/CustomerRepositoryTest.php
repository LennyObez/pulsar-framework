<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\Customer;
use Pulsar\Extension\Cms\Commerce\CustomerRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Persistence\DbCustomerRepository;

#[CoversClass(DbCustomerRepository::class)]
#[CoversClass(Customer::class)]
final class CustomerRepositoryTest extends TestCase
{
    #[Test]
    public function save_and_find_by_id_roundtrip(): void
    {
        $now = new DateTimeImmutable('2025-06-01T10:00:00+00:00');

        $customer = new Customer(
            id: '019012ab-cdef-7000-8000-000000000001',
            tenantId: null,
            userId: 'user-001',
            email: 'alice@example.com',
            displayName: 'Alice',
            billingAddress: ['line1' => '123 Main St', 'city' => 'London', 'postalCode' => 'SW1A 1AA', 'country' => 'GB'],
            shippingAddress: ['line1' => '456 Oak Ave', 'city' => 'Manchester', 'postalCode' => 'M1 1AA', 'country' => 'GB'],
            createdAt: $now,
            updatedAt: $now,
        );

        $result = new Result([new Row([
            'id' => $customer->id,
            'tenant_id' => null,
            'user_id' => 'user-001',
            'email' => 'alice@example.com',
            'display_name' => 'Alice',
            'billing_address' => '{"line1":"123 Main St","city":"London","postalCode":"SW1A 1AA","country":"GB"}',
            'shipping_address' => '{"line1":"456 Oak Ave","city":"Manchester","postalCode":"M1 1AA","country":"GB"}',
            'created_at' => '2025-06-01T10:00:00+00:00',
            'updated_at' => '2025-06-01T10:00:00+00:00',
        ])]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $repo = new DbCustomerRepository($connection);
        $repo->save($customer);

        $found = $repo->findById($customer->id);

        self::assertNotNull($found);
        self::assertSame($customer->id, $found->id);
        self::assertSame('alice@example.com', $found->email);
        self::assertSame('Alice', $found->displayName);
        self::assertSame('user-001', $found->userId);
        self::assertNull($found->tenantId);
        self::assertIsArray($found->billingAddress);
        self::assertSame('123 Main St', $found->billingAddress['line1']);
        self::assertIsArray($found->shippingAddress);
        self::assertSame('456 Oak Ave', $found->shippingAddress['line1']);
    }

    #[Test]
    public function find_by_email_returns_customer(): void
    {
        $result = new Result([new Row([
            'id' => '019012ab-cdef-7000-8000-000000000002',
            'tenant_id' => 'tenant-01',
            'user_id' => null,
            'email' => 'bob@example.com',
            'display_name' => null,
            'billing_address' => null,
            'shipping_address' => null,
            'created_at' => '2025-06-01T12:00:00+00:00',
            'updated_at' => '2025-06-01T12:00:00+00:00',
        ])]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $repo = new DbCustomerRepository($connection);
        $found = $repo->findByEmail('bob@example.com', 'tenant-01');

        self::assertNotNull($found);
        self::assertSame('bob@example.com', $found->email);
        self::assertSame('tenant-01', $found->tenantId);
        self::assertNull($found->userId);
        self::assertNull($found->displayName);
        self::assertNull($found->billingAddress);
        self::assertNull($found->shippingAddress);
    }

    #[Test]
    public function find_by_user_id_returns_customer(): void
    {
        $result = new Result([new Row([
            'id' => '019012ab-cdef-7000-8000-000000000003',
            'tenant_id' => null,
            'user_id' => 'user-999',
            'email' => 'charlie@example.com',
            'display_name' => 'Charlie',
            'billing_address' => null,
            'shipping_address' => null,
            'created_at' => '2025-07-01T08:00:00+00:00',
            'updated_at' => '2025-07-01T08:00:00+00:00',
        ])]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $repo = new DbCustomerRepository($connection);
        $found = $repo->findByUserId('user-999');

        self::assertNotNull($found);
        self::assertSame('user-999', $found->userId);
        self::assertSame('charlie@example.com', $found->email);
        self::assertTrue($found->hasLinkedUser());
    }

    #[Test]
    public function find_by_id_returns_null_when_not_found(): void
    {
        $result = new Result([]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);

        $repo = new DbCustomerRepository($connection);
        $found = $repo->findById('nonexistent');

        self::assertNull($found);
    }

    #[Test]
    public function customer_without_linked_user(): void
    {
        $customer = new Customer(
            id: '019012ab-cdef-7000-8000-000000000004',
            tenantId: null,
            userId: null,
            email: 'guest@example.com',
            displayName: null,
            billingAddress: null,
            shippingAddress: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        self::assertFalse($customer->hasLinkedUser());
    }

    #[Test]
    public function interface_is_implemented(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $repo = new DbCustomerRepository($connection);

        self::assertInstanceOf(CustomerRepositoryInterface::class, $repo);
    }
}
