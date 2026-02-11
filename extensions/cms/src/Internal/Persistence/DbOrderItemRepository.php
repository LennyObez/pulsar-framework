<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\OrderItem;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed order item repository.
 */
#[Internal(reason: 'Use OrderItemRepositoryInterface for public API')]
final readonly class DbOrderItemRepository implements OrderItemRepositoryInterface
{
    private const string SQL_FIND_BY_ORDER = <<<'SQL'
        SELECT * FROM cms_order_items WHERE order_id = :order_id ORDER BY id ASC
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'order_id', 'product_id', 'variant_id', 'quantity',
        'unit_price', 'total_price', 'tax_amount', 'discount_amount', 'product_snapshot',
    ];

    private const array UPSERT_UPDATE = [
        'quantity', 'unit_price', 'total_price', 'tax_amount', 'discount_amount', 'product_snapshot',
    ];

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findByOrder(string $orderId): array
    {
        return $this->db->query(self::SQL_FIND_BY_ORDER, ['order_id' => $orderId])
            ->map(self::hydrate(...));
    }

    public function save(OrderItem $item): void
    {
        $sql = UpsertBuilder::compile(
            $this->db->driver(),
            'cms_order_items',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->db->execute($sql, [
            'id' => $item->id,
            'order_id' => $item->orderId,
            'product_id' => $item->productId,
            'variant_id' => $item->variantId,
            'quantity' => $item->quantity,
            'unit_price' => $item->unitPrice,
            'total_price' => $item->totalPrice,
            'tax_amount' => $item->taxAmount,
            'discount_amount' => $item->discountAmount,
            'product_snapshot' => json_encode($item->productSnapshot, JSON_THROW_ON_ERROR),
        ]);
    }

    private static function hydrate(Row $row): OrderItem
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($row->getString('product_snapshot'), true, 512, JSON_THROW_ON_ERROR);

        return new OrderItem(
            id: $row->getString('id'),
            orderId: $row->getString('order_id'),
            productId: $row->getString('product_id'),
            variantId: $row->getNullableString('variant_id'),
            quantity: $row->getInt('quantity'),
            unitPrice: $row->getInt('unit_price'),
            totalPrice: $row->getInt('total_price'),
            taxAmount: $row->getInt('tax_amount'),
            discountAmount: $row->getInt('discount_amount'),
            productSnapshot: $snapshot,
        );
    }
}
