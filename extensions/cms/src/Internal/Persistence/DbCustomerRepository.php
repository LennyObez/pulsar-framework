<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\Customer;
use Pulsar\Extension\Cms\Commerce\CustomerRepositoryInterface;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed customer repository with tenant scoping.
 */
#[Internal(reason: 'Use CustomerRepositoryInterface for public API')]
final readonly class DbCustomerRepository implements CustomerRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_customers WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_EMAIL = <<<'SQL'
        SELECT * FROM cms_customers WHERE email = :email
        SQL;

    private const string SQL_FIND_BY_USER_ID = <<<'SQL'
        SELECT * FROM cms_customers WHERE user_id = :user_id
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO cms_customers (
            id, tenant_id, user_id, email, display_name,
            billing_address, shipping_address, created_at, updated_at
        ) VALUES (
            :id, :tenant_id, :user_id, :email, :display_name,
            :billing_address, :shipping_address, :created_at, :updated_at
        )
        ON CONFLICT (id) DO UPDATE SET
            user_id = EXCLUDED.user_id,
            email = EXCLUDED.email,
            display_name = EXCLUDED.display_name,
            billing_address = EXCLUDED.billing_address,
            shipping_address = EXCLUDED.shipping_address,
            updated_at = EXCLUDED.updated_at
        SQL;

    public function __construct(
        private ConnectionInterface $db,
        private ?string $tenantId = null,
    ) {}

    public function findById(string $id): ?Customer
    {
        $row = $this->db->query(self::SQL_FIND_BY_ID, ['id' => $id])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByEmail(string $email, ?string $tenantId = null): ?Customer
    {
        $sql = self::SQL_FIND_BY_EMAIL;
        $bindings = ['email' => $email];
        $effectiveTenantId = $tenantId ?? $this->tenantId;

        if ($effectiveTenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $effectiveTenantId;
        }

        $row = $this->db->query($sql, $bindings)->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByUserId(string $userId): ?Customer
    {
        $row = $this->db->query(self::SQL_FIND_BY_USER_ID, ['user_id' => $userId])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function save(Customer $customer): void
    {
        $this->db->execute(self::SQL_UPSERT, [
            'id' => $customer->id,
            'tenant_id' => $customer->tenantId,
            'user_id' => $customer->userId,
            'email' => $customer->email,
            'display_name' => $customer->displayName,
            'billing_address' => $customer->billingAddress !== null
                ? json_encode($customer->billingAddress, JSON_THROW_ON_ERROR)
                : null,
            'shipping_address' => $customer->shippingAddress !== null
                ? json_encode($customer->shippingAddress, JSON_THROW_ON_ERROR)
                : null,
            'created_at' => $customer->createdAt->format('c'),
            'updated_at' => $customer->updatedAt->format('c'),
        ]);
    }

    private static function hydrate(Row $row): Customer
    {
        $billingRaw = $row->getNullableString('billing_address');
        /** @var array<string, mixed>|null $billingAddress */
        $billingAddress = $billingRaw !== null
            ? json_decode($billingRaw, true, 512, JSON_THROW_ON_ERROR)
            : null;

        $shippingRaw = $row->getNullableString('shipping_address');
        /** @var array<string, mixed>|null $shippingAddress */
        $shippingAddress = $shippingRaw !== null
            ? json_decode($shippingRaw, true, 512, JSON_THROW_ON_ERROR)
            : null;

        return new Customer(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            userId: $row->getNullableString('user_id'),
            email: $row->getString('email'),
            displayName: $row->getNullableString('display_name'),
            billingAddress: $billingAddress,
            shippingAddress: $shippingAddress,
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }
}
