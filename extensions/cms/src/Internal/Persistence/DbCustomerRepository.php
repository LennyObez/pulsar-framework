<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\Customer;
use Pulsar\Extension\Cms\Commerce\CustomerRepositoryInterface;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed customer repository with tenant scoping.
 *
 * @psalm-api Bound to CustomerRepositoryInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
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

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'user_id', 'email', 'display_name',
        'billing_address', 'shipping_address', 'notes', 'created_at', 'updated_at',
    ];

    private const array UPSERT_UPDATE = [
        'user_id', 'email', 'display_name', 'billing_address', 'shipping_address', 'notes', 'updated_at',
    ];

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
        $sql = UpsertBuilder::compile(
            $this->db->driver(),
            'cms_customers',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->db->execute($sql, [
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
            'notes' => $customer->notes,
            'created_at' => $customer->createdAt->format('c'),
            'updated_at' => $customer->updatedAt->format('c'),
        ]);
    }

    private static function hydrate(Row $row): Customer
    {
        $billingRaw = $row->getNullableString('billing_address');
        /** @var array<string, mixed>|null $billingAddress */
        $billingAddress = $billingRaw !== null
            ? json_decode($billingRaw, true, flags: JSON_THROW_ON_ERROR)
            : null;

        $shippingRaw = $row->getNullableString('shipping_address');
        /** @var array<string, mixed>|null $shippingAddress */
        $shippingAddress = $shippingRaw !== null
            ? json_decode($shippingRaw, true, flags: JSON_THROW_ON_ERROR)
            : null;

        return new Customer(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            userId: $row->getNullableString('user_id'),
            email: $row->getString('email'),
            displayName: $row->getNullableString('display_name'),
            billingAddress: $billingAddress,
            shippingAddress: $shippingAddress,
            notes: $row->getNullableString('notes'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }

    /**
     * @return PaginationResult<Customer>
     */
    public function listCustomers(
        ?string $tenantId = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $conditions = [];
        $bindings = [];
        $effectiveTenantId = $tenantId ?? $this->tenantId;

        if ($effectiveTenantId !== null) {
            $conditions[] = 'tenant_id = :tenant_id';
            $bindings['tenant_id'] = $effectiveTenantId;
        }

        if ($search !== null && $search !== '') {
            $conditions[] = '(email LIKE :search OR display_name LIKE :search)';
            $bindings['search'] = '%' . $search . '%';
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Count total
        $countSql = "SELECT COUNT(*) AS cnt FROM cms_customers {$where}";
        $countRow = $this->db->query($countSql, $bindings)->first();
        $total = $countRow !== null ? (int) $countRow->getString('cnt') : 0;

        // Fetch page
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM cms_customers {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}";
        $rows = $this->db->query($sql, $bindings);

        $items = [];

        foreach ($rows->rows as $row) {
            $items[] = self::hydrate($row);
        }

        $lastPage = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }
}
