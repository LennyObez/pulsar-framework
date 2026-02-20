<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Cms\Commerce\Customer;
use Pulsar\Extension\Cms\Internal\Persistence\DbCustomerRepository;

#[CoversClass(DbCustomerRepository::class)]
final class DbCustomerRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbCustomerRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_customers (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                user_id VARCHAR(36) DEFAULT NULL,
                email VARCHAR(255) NOT NULL,
                display_name VARCHAR(200) DEFAULT NULL,
                billing_address TEXT DEFAULT NULL,
                shipping_address TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        $this->repository = new DbCustomerRepository($this->connection);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $now = new DateTimeImmutable();
        $customer = new Customer(
            id: 'cust-001',
            tenantId: null,
            userId: 'user-001',
            email: 'alice@example.com',
            displayName: 'Alice',
            billingAddress: ['street' => '123 Main St', 'city' => 'Springfield'],
            shippingAddress: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->repository->save($customer);

        $found = $this->repository->findById('cust-001');

        self::assertNotNull($found);
        self::assertSame('cust-001', $found->id);
        self::assertSame('user-001', $found->userId);
        self::assertSame('alice@example.com', $found->email);
        self::assertSame('Alice', $found->displayName);
        self::assertSame('123 Main St', $found->billingAddress['street']);
        self::assertNull($found->shippingAddress);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findByEmailReturnsCustomer(): void
    {
        $now = new DateTimeImmutable();
        $customer = new Customer(
            id: 'cust-email',
            tenantId: null,
            userId: null,
            email: 'bob@example.com',
            displayName: 'Bob',
            billingAddress: null,
            shippingAddress: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->repository->save($customer);

        $found = $this->repository->findByEmail('bob@example.com');

        self::assertNotNull($found);
        self::assertSame('cust-email', $found->id);
        self::assertSame('bob@example.com', $found->email);
    }

    #[Test]
    public function findByEmailReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByEmail('notfound@example.com'));
    }

    #[Test]
    public function findByUserIdReturnsCustomer(): void
    {
        $now = new DateTimeImmutable();
        $customer = new Customer(
            id: 'cust-uid',
            tenantId: null,
            userId: 'user-linked',
            email: 'linked@example.com',
            displayName: 'Linked User',
            billingAddress: null,
            shippingAddress: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->repository->save($customer);

        $found = $this->repository->findByUserId('user-linked');

        self::assertNotNull($found);
        self::assertSame('cust-uid', $found->id);
        self::assertTrue($found->hasLinkedUser());
    }

    #[Test]
    public function findByUserIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByUserId('nonexistent'));
    }

    #[Test]
    public function saveUpdatesExistingCustomer(): void
    {
        $now = new DateTimeImmutable();
        $customer = new Customer(
            id: 'cust-upd',
            tenantId: null,
            userId: 'user-upd',
            email: 'original@example.com',
            displayName: 'Original',
            billingAddress: null,
            shippingAddress: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->repository->save($customer);

        $updated = new Customer(
            id: 'cust-upd',
            tenantId: null,
            userId: 'user-upd',
            email: 'updated@example.com',
            displayName: 'Updated Name',
            billingAddress: ['street' => '456 Oak Ave'],
            shippingAddress: ['street' => '789 Pine Rd'],
            createdAt: $now,
            updatedAt: new DateTimeImmutable(),
        );
        $this->repository->save($updated);

        $found = $this->repository->findById('cust-upd');
        self::assertNotNull($found);
        self::assertSame('updated@example.com', $found->email);
        self::assertSame('Updated Name', $found->displayName);
        self::assertSame('456 Oak Ave', $found->billingAddress['street']);
        self::assertSame('789 Pine Rd', $found->shippingAddress['street']);
    }

    #[Test]
    public function customerWithBothAddresses(): void
    {
        $now = new DateTimeImmutable();
        $billing = [
            'street' => '100 Business Blvd',
            'city' => 'Commerce City',
            'zip' => '12345',
        ];
        $shipping = [
            'street' => '200 Shipping Lane',
            'city' => 'Delivery Town',
            'zip' => '67890',
        ];

        $customer = new Customer(
            id: 'cust-addr',
            tenantId: null,
            userId: 'user-addr',
            email: 'addr@example.com',
            displayName: 'Addr User',
            billingAddress: $billing,
            shippingAddress: $shipping,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->repository->save($customer);

        $found = $this->repository->findById('cust-addr');
        self::assertNotNull($found);
        self::assertSame('100 Business Blvd', $found->billingAddress['street']);
        self::assertSame('200 Shipping Lane', $found->shippingAddress['street']);
    }

    #[Test]
    public function customerWithNullDisplayName(): void
    {
        $now = new DateTimeImmutable();
        $customer = new Customer(
            id: 'cust-noname',
            tenantId: null,
            userId: null,
            email: 'noname@example.com',
            displayName: null,
            billingAddress: null,
            shippingAddress: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->repository->save($customer);

        $found = $this->repository->findById('cust-noname');
        self::assertNotNull($found);
        self::assertNull($found->displayName);
        self::assertFalse($found->hasLinkedUser());
    }
}
