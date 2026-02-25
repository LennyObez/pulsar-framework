<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\Customer;
use Pulsar\Extension\Cms\Internal\Persistence\DbCustomerRepository;

#[CoversClass(DbCustomerRepository::class)]
final class DbCustomerRepositoryTest extends TestCase
{
    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([]));

        $repo = new DbCustomerRepository($db);

        self::assertNull($repo->findById('nonexistent'));
    }

    #[Test]
    public function findByIdReturnsHydratedCustomer(): void
    {
        $row = $this->createCustomerRow([
            'id' => 'cust-1',
            'tenant_id' => 'tenant-1',
            'user_id' => 'user-1',
            'email' => 'jane@example.com',
            'display_name' => 'Jane Doe',
            'billing_address' => '{"street":"123 Main St","city":"Springfield"}',
            'shipping_address' => null,
            'created_at' => '2024-06-15T10:00:00+00:00',
            'updated_at' => '2024-06-16T12:00:00+00:00',
        ]);

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([$row]));

        $repo = new DbCustomerRepository($db);
        $customer = $repo->findById('cust-1');

        self::assertNotNull($customer);
        self::assertSame('cust-1', $customer->id);
        self::assertSame('tenant-1', $customer->tenantId);
        self::assertSame('user-1', $customer->userId);
        self::assertSame('jane@example.com', $customer->email);
        self::assertSame('Jane Doe', $customer->displayName);
        self::assertIsArray($customer->billingAddress);
        self::assertSame('123 Main St', $customer->billingAddress['street']);
        self::assertNull($customer->shippingAddress);
    }

    #[Test]
    public function findByEmailReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([]));

        $repo = new DbCustomerRepository($db);

        self::assertNull($repo->findByEmail('unknown@example.com'));
    }

    #[Test]
    public function findByEmailReturnsCustomer(): void
    {
        $row = $this->createCustomerRow([
            'id' => 'cust-2',
            'tenant_id' => null,
            'user_id' => null,
            'email' => 'john@example.com',
            'display_name' => null,
            'billing_address' => null,
            'shipping_address' => null,
            'created_at' => '2024-06-15T10:00:00+00:00',
            'updated_at' => '2024-06-15T10:00:00+00:00',
        ]);

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([$row]));

        $repo = new DbCustomerRepository($db);
        $customer = $repo->findByEmail('john@example.com');

        self::assertNotNull($customer);
        self::assertSame('john@example.com', $customer->email);
        self::assertNull($customer->tenantId);
        self::assertNull($customer->userId);
        self::assertNull($customer->displayName);
    }

    #[Test]
    public function findByEmailWithTenantScopeUsesRepoTenant(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('tenant_id'),
                self::callback(static function (array $bindings): bool {
                    return isset($bindings['tenant_id'])
                        && $bindings['tenant_id'] === 'tenant-abc';
                }),
            )
            ->willReturn(new Result([]));

        $repo = new DbCustomerRepository($db, tenantId: 'tenant-abc');
        $repo->findByEmail('test@example.com');
    }

    #[Test]
    public function findByEmailWithExplicitTenantOverridesDefault(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('tenant_id'),
                self::callback(static function (array $bindings): bool {
                    return isset($bindings['tenant_id'])
                        && $bindings['tenant_id'] === 'explicit-tenant';
                }),
            )
            ->willReturn(new Result([]));

        $repo = new DbCustomerRepository($db, tenantId: 'default-tenant');
        $repo->findByEmail('test@example.com', tenantId: 'explicit-tenant');
    }

    #[Test]
    public function findByEmailWithoutTenantDoesNotFilterByTenant(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::logicalNot(self::stringContains('tenant_id')),
                self::callback(static function (array $bindings): bool {
                    return !isset($bindings['tenant_id']);
                }),
            )
            ->willReturn(new Result([]));

        $repo = new DbCustomerRepository($db);
        $repo->findByEmail('test@example.com');
    }

    #[Test]
    public function findByUserIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([]));

        $repo = new DbCustomerRepository($db);

        self::assertNull($repo->findByUserId('nonexistent-user'));
    }

    #[Test]
    public function findByUserIdReturnsCustomer(): void
    {
        $row = $this->createCustomerRow([
            'id' => 'cust-3',
            'tenant_id' => null,
            'user_id' => 'user-42',
            'email' => 'linked@example.com',
            'display_name' => 'Linked User',
            'billing_address' => null,
            'shipping_address' => '{"country":"US","state":"CA"}',
            'created_at' => '2024-01-01T00:00:00+00:00',
            'updated_at' => '2024-06-15T10:00:00+00:00',
        ]);

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([$row]));

        $repo = new DbCustomerRepository($db);
        $customer = $repo->findByUserId('user-42');

        self::assertNotNull($customer);
        self::assertSame('user-42', $customer->userId);
        self::assertIsArray($customer->shippingAddress);
        self::assertSame('US', $customer->shippingAddress['country']);
    }

    #[Test]
    public function saveCallsExecuteWithCorrectBindings(): void
    {
        $now = new DateTimeImmutable('2024-06-15T10:00:00+00:00');
        $customer = new Customer(
            id: 'cust-save',
            tenantId: 'tenant-1',
            userId: 'user-1',
            email: 'save@example.com',
            displayName: 'Save Test',
            billingAddress: ['street' => '456 Oak Ave'],
            shippingAddress: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO cms_customers'),
                self::callback(static function (array $bindings) use ($now): bool {
                    return $bindings['id'] === 'cust-save'
                        && $bindings['tenant_id'] === 'tenant-1'
                        && $bindings['user_id'] === 'user-1'
                        && $bindings['email'] === 'save@example.com'
                        && $bindings['display_name'] === 'Save Test'
                        && str_contains($bindings['billing_address'], '456 Oak Ave')
                        && $bindings['shipping_address'] === null
                        && $bindings['created_at'] === $now->format('c')
                        && $bindings['updated_at'] === $now->format('c');
                }),
            );

        $repo = new DbCustomerRepository($db);
        $repo->save($customer);
    }

    #[Test]
    public function saveSerializesBothAddressesAsJson(): void
    {
        $now = new DateTimeImmutable();
        $customer = new Customer(
            id: 'cust-addr',
            tenantId: null,
            userId: null,
            email: 'addr@example.com',
            displayName: null,
            billingAddress: ['city' => 'NYC'],
            shippingAddress: ['city' => 'LA'],
            createdAt: $now,
            updatedAt: $now,
        );

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static function (array $bindings): bool {
                    return $bindings['billing_address'] !== null
                        && $bindings['shipping_address'] !== null
                        && str_contains($bindings['billing_address'], 'NYC')
                        && str_contains($bindings['shipping_address'], 'LA');
                }),
            );

        $repo = new DbCustomerRepository($db);
        $repo->save($customer);
    }

    #[Test]
    public function hydrateHandlesNullBillingAndShippingAddress(): void
    {
        $row = $this->createCustomerRow([
            'id' => 'cust-null-addr',
            'tenant_id' => null,
            'user_id' => null,
            'email' => 'null@example.com',
            'display_name' => null,
            'billing_address' => null,
            'shipping_address' => null,
            'created_at' => '2024-01-01T00:00:00+00:00',
            'updated_at' => '2024-01-01T00:00:00+00:00',
        ]);

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('query')->willReturn(new Result([$row]));

        $repo = new DbCustomerRepository($db);
        $customer = $repo->findById('cust-null-addr');

        self::assertNotNull($customer);
        self::assertNull($customer->billingAddress);
        self::assertNull($customer->shippingAddress);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createCustomerRow(array $data): Row
    {
        return new Row($data);
    }
}
