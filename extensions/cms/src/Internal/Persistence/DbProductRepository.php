<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductStatus;

use function implode;
use function is_string;
use function sprintf;

/**
 * Database-backed product repository with tenant scoping.
 *
 * @psalm-api Bound to ProductRepositoryInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use ProductRepositoryInterface for public API')]
final readonly class DbProductRepository implements ProductRepositoryInterface
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_products
        WHERE id = :id
            AND COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_FIND_BY_SKU = <<<'SQL'
        SELECT * FROM cms_products WHERE sku = :sku
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'sku', 'status', 'price_amount', 'price_currency',
        'tax_category', 'stock_quantity', 'digital', 'content_id', 'created_at', 'updated_at',
    ];

    private const array UPSERT_UPDATE = [
        'sku', 'status', 'price_amount', 'price_currency', 'tax_category',
        'stock_quantity', 'digital', 'content_id', 'updated_at',
    ];

    public function __construct(
        private ConnectionInterface $db,
        private ?string $tenantId = null,
    ) {}

    public function findById(string $id): ?Product
    {
        $tenantKey = $this->tenantId ?? self::SENTINEL_TENANT;
        $row = $this->db->query(self::SQL_FIND_BY_ID, ['id' => $id, 'tenant_key' => $tenantKey])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];

        foreach ($ids as $i => $id) {
            $key = 'id_' . $i;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $id;
        }

        $tenantKey = $this->tenantId ?? self::SENTINEL_TENANT;
        $bindings['tenant_key'] = $tenantKey;

        $sql = sprintf(
            "SELECT * FROM cms_products WHERE id IN (%s) AND COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key",
            implode(', ', $placeholders),
        );
        $rows = $this->db->query($sql, $bindings)->map(self::hydrate(...));

        $result = [];

        foreach ($rows as $product) {
            $result[$product->id] = $product;
        }

        return $result;
    }

    public function findByContentId(string $contentId): ?Product
    {
        $sql = 'SELECT * FROM cms_products WHERE content_id = :content_id';
        $bindings = ['content_id' => $contentId];

        if ($this->tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $this->tenantId;
        }

        $row = $this->db->query($sql, $bindings)->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findBySku(string $sku, ?string $tenantId = null): ?Product
    {
        $sql = self::SQL_FIND_BY_SKU;
        $bindings = ['sku' => $sku];
        $effectiveTenantId = $tenantId ?? $this->tenantId;

        if ($effectiveTenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $effectiveTenantId;
        }

        $row = $this->db->query($sql, $bindings)->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function listProducts(array $filters, int $page, int $perPage): array
    {
        $sql = 'SELECT * FROM cms_products WHERE 1=1';
        /** @var array<string, mixed> $bindings */
        $bindings = [];

        /** @var mixed $rawStatus */
        $rawStatus = $filters['status'] ?? null;
        if (is_string($rawStatus)) {
            $sql .= ' AND status = :status';
            $bindings['status'] = $rawStatus;
        }

        /** @var mixed $rawTenantId */
        $rawTenantId = $filters['tenantId'] ?? null;
        if (is_string($rawTenantId)) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $rawTenantId;
        } elseif ($this->tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $this->tenantId;
        }

        if (isset($filters['digital'])) {
            $sql .= ' AND digital = :digital';
            $bindings['digital'] = $filters['digital'] ? 1 : 0;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
        $bindings['limit'] = $perPage;
        $bindings['offset'] = ($page - 1) * $perPage;

        return $this->db->query($sql, $bindings)->map(self::hydrate(...));
    }

    public function save(Product $product): void
    {
        $sql = UpsertBuilder::compile(
            $this->db->driver(),
            'cms_products',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->db->execute($sql, [
            'id' => $product->id,
            'tenant_id' => $product->tenantId,
            'sku' => $product->sku,
            'status' => $product->status->value,
            'price_amount' => $product->priceAmount,
            'price_currency' => $product->priceCurrency,
            'tax_category' => $product->taxCategory,
            'stock_quantity' => $product->stockQuantity,
            'digital' => $product->digital ? 1 : 0,
            'content_id' => $product->contentId,
            'created_at' => $product->createdAt->format('c'),
            'updated_at' => $product->updatedAt->format('c'),
        ]);
    }

    public function reserveStock(string $productId, int $quantity): bool
    {
        $affected = $this->db->execute(
            'UPDATE cms_products SET stock_quantity = stock_quantity - :qty, updated_at = :now WHERE id = :id AND stock_quantity >= :qty',
            [
                'qty' => $quantity,
                'now' => new DateTimeImmutable()->format('c'),
                'id' => $productId,
            ],
        );

        return $affected > 0;
    }

    public function restoreStock(string $productId, int $quantity): void
    {
        $this->db->execute(
            'UPDATE cms_products SET stock_quantity = stock_quantity + :qty, updated_at = :now WHERE id = :id',
            [
                'qty' => $quantity,
                'now' => new DateTimeImmutable()->format('c'),
                'id' => $productId,
            ],
        );
    }

    private static function hydrate(Row $row): Product
    {
        return new Product(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            sku: $row->getString('sku'),
            status: ProductStatus::from($row->getString('status')),
            priceAmount: $row->getInt('price_amount'),
            priceCurrency: $row->getString('price_currency'),
            taxCategory: $row->getNullableString('tax_category'),
            stockQuantity: $row->getInt('stock_quantity'),
            digital: (bool) $row->getInt('digital'),
            contentId: $row->getNullableString('content_id'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }
}
