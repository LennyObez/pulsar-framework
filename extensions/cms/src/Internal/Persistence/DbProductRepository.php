<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductStatus;

/**
 * Database-backed product repository with tenant scoping.
 */
#[Internal(reason: 'Use ProductRepositoryInterface for public API')]
final readonly class DbProductRepository implements ProductRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_products WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_SKU = <<<'SQL'
        SELECT * FROM cms_products WHERE sku = :sku
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO cms_products (
            id, tenant_id, sku, status, price_amount, price_currency,
            tax_category, stock_quantity, digital, content_id, created_at, updated_at
        ) VALUES (
            :id, :tenant_id, :sku, :status, :price_amount, :price_currency,
            :tax_category, :stock_quantity, :digital, :content_id, :created_at, :updated_at
        )
        ON CONFLICT (id) DO UPDATE SET
            sku = EXCLUDED.sku,
            status = EXCLUDED.status,
            price_amount = EXCLUDED.price_amount,
            price_currency = EXCLUDED.price_currency,
            tax_category = EXCLUDED.tax_category,
            stock_quantity = EXCLUDED.stock_quantity,
            digital = EXCLUDED.digital,
            content_id = EXCLUDED.content_id,
            updated_at = EXCLUDED.updated_at
        SQL;

    public function __construct(
        private ConnectionInterface $db,
        private ?string $tenantId = null,
    ) {}

    public function findById(string $id): ?Product
    {
        $row = $this->db->query(self::SQL_FIND_BY_ID, ['id' => $id])->first();

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
        $bindings = [];

        if (isset($filters['status'])) {
            $sql .= ' AND status = :status';
            $bindings['status'] = $filters['status'];
        }

        if (isset($filters['tenantId'])) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $filters['tenantId'];
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
        $this->db->execute(self::SQL_UPSERT, [
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
            'created_at' => $product->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $product->updatedAt->format('Y-m-d H:i:s'),
        ]);
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
