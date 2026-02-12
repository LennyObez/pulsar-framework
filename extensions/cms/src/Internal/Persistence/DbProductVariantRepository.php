<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\ProductVariant;
use Pulsar\Extension\Cms\Commerce\ProductVariantRepositoryInterface;

use function array_values;
use function implode;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed product variant repository querying cms_product_variants.
 */
#[Internal(reason: 'Use ProductVariantRepositoryInterface for public API')]
final readonly class DbProductVariantRepository implements ProductVariantRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_product_variants WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_PRODUCT = <<<'SQL'
        SELECT * FROM cms_product_variants WHERE product_id = :product_id ORDER BY sort_order
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO cms_product_variants (
            id, product_id, sku_suffix, attribute_values, price_modifier,
            stock_quantity, media_asset_id, sort_order, is_active
        ) VALUES (
            :id, :product_id, :sku_suffix, :attribute_values, :price_modifier,
            :stock_quantity, :media_asset_id, :sort_order, :is_active
        )
        ON CONFLICT (id) DO UPDATE SET
            sku_suffix = EXCLUDED.sku_suffix,
            attribute_values = EXCLUDED.attribute_values,
            price_modifier = EXCLUDED.price_modifier,
            stock_quantity = EXCLUDED.stock_quantity,
            media_asset_id = EXCLUDED.media_asset_id,
            sort_order = EXCLUDED.sort_order,
            is_active = EXCLUDED.is_active
        SQL;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findById(string $id): ?ProductVariant
    {
        $row = $this->db->query(self::SQL_FIND_BY_ID, ['id' => $id])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];

        foreach (array_values($ids) as $i => $id) {
            $key = 'id_' . $i;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $id;
        }

        $sql = sprintf(
            'SELECT * FROM cms_product_variants WHERE id IN (%s)',
            implode(', ', $placeholders),
        );

        $rows = $this->db->query($sql, $bindings)->map(self::hydrate(...));

        $result = [];

        foreach ($rows as $variant) {
            $result[$variant->id] = $variant;
        }

        return $result;
    }

    public function findByProductId(string $productId): array
    {
        return $this->db->query(self::SQL_FIND_BY_PRODUCT, ['product_id' => $productId])
            ->map(self::hydrate(...));
    }

    public function save(ProductVariant $variant): void
    {
        $this->db->execute(self::SQL_UPSERT, [
            'id' => $variant->id,
            'product_id' => $variant->productId,
            'sku_suffix' => $variant->skuSuffix,
            'attribute_values' => json_encode($variant->attributeValues, JSON_THROW_ON_ERROR),
            'price_modifier' => $variant->priceModifier,
            'stock_quantity' => $variant->stockQuantity,
            'media_asset_id' => $variant->mediaAssetId,
            'sort_order' => $variant->sortOrder,
            'is_active' => $variant->isActive ? 1 : 0,
        ]);
    }

    public function reserveStock(string $variantId, int $quantity): bool
    {
        $affected = $this->db->execute(
            'UPDATE cms_product_variants SET stock_quantity = stock_quantity - :qty WHERE id = :id AND stock_quantity >= :qty',
            [
                'qty' => $quantity,
                'id' => $variantId,
            ],
        );

        return $affected > 0;
    }

    public function restoreStock(string $variantId, int $quantity): void
    {
        $this->db->execute(
            'UPDATE cms_product_variants SET stock_quantity = stock_quantity + :qty WHERE id = :id',
            [
                'qty' => $quantity,
                'id' => $variantId,
            ],
        );
    }

    private static function hydrate(Row $row): ProductVariant
    {
        /** @var array<string, string> $attributeValues */
        $attributeValues = json_decode($row->getString('attribute_values'), true, 512, JSON_THROW_ON_ERROR);

        return new ProductVariant(
            id: $row->getString('id'),
            productId: $row->getString('product_id'),
            skuSuffix: $row->getString('sku_suffix'),
            attributeValues: $attributeValues,
            priceModifier: $row->getInt('price_modifier'),
            stockQuantity: $row->getInt('stock_quantity'),
            mediaAssetId: $row->getNullableString('media_asset_id'),
            sortOrder: $row->getInt('sort_order'),
            isActive: (bool) $row->getInt('is_active'),
        );
    }
}
