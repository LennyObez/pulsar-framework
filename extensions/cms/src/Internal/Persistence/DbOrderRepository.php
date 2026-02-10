<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\OrderStatusStateMachine;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Exception\CmsException;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed order repository with state machine validation on status updates.
 */
#[Internal(reason: 'Use OrderRepositoryInterface for public API')]
final readonly class DbOrderRepository implements OrderRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_orders WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_NUMBER = <<<'SQL'
        SELECT * FROM cms_orders WHERE order_number = :order_number
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO cms_orders (
            id, tenant_id, order_number, customer_id, customer_email, status,
            subtotal, tax_amount, discount_amount, shipping_amount, shipping_method,
            total, amount_refunded, currency,
            payment_intent_id, payment_status, billing_address, shipping_address,
            notes, data_classification, created_at, updated_at
        ) VALUES (
            :id, :tenant_id, :order_number, :customer_id, :customer_email, :status,
            :subtotal, :tax_amount, :discount_amount, :shipping_amount, :shipping_method,
            :total, :amount_refunded, :currency,
            :payment_intent_id, :payment_status, :billing_address, :shipping_address,
            :notes, :data_classification, :created_at, :updated_at
        )
        ON CONFLICT (id) DO UPDATE SET
            status = EXCLUDED.status,
            subtotal = EXCLUDED.subtotal,
            tax_amount = EXCLUDED.tax_amount,
            discount_amount = EXCLUDED.discount_amount,
            shipping_amount = EXCLUDED.shipping_amount,
            shipping_method = EXCLUDED.shipping_method,
            total = EXCLUDED.total,
            amount_refunded = EXCLUDED.amount_refunded,
            payment_intent_id = EXCLUDED.payment_intent_id,
            payment_status = EXCLUDED.payment_status,
            billing_address = EXCLUDED.billing_address,
            shipping_address = EXCLUDED.shipping_address,
            notes = EXCLUDED.notes,
            updated_at = EXCLUDED.updated_at
        SQL;

    private const string SQL_FIND_BY_CUSTOMER = <<<'SQL'
        SELECT * FROM cms_orders
        WHERE customer_id = :customer_id
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_UPDATE_STATUS = <<<'SQL'
        UPDATE cms_orders SET status = :new_status, updated_at = :now WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $db,
        private ?string $tenantId = null,
    ) {}

    public function findById(string $id): ?Order
    {
        $row = $this->db->query(self::SQL_FIND_BY_ID, ['id' => $id])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByNumber(string $orderNumber, ?string $tenantId = null): ?Order
    {
        $sql = self::SQL_FIND_BY_NUMBER;
        $bindings = ['order_number' => $orderNumber];
        $effectiveTenantId = $tenantId ?? $this->tenantId;

        if ($effectiveTenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $effectiveTenantId;
        }

        $row = $this->db->query($sql, $bindings)->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function listOrders(array $filters, int $page, int $perPage): array
    {
        $sql = 'SELECT * FROM cms_orders WHERE 1=1';
        $bindings = [];

        if (isset($filters['status'])) {
            $sql .= ' AND status = :status';
            $bindings['status'] = $filters['status'];
        }

        if (isset($filters['customerId'])) {
            $sql .= ' AND customer_id = :customer_id';
            $bindings['customer_id'] = $filters['customerId'];
        }

        if (isset($filters['tenantId'])) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $filters['tenantId'];
        } elseif ($this->tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $this->tenantId;
        }

        if (isset($filters['dateFrom'])) {
            $sql .= ' AND created_at >= :date_from';
            $bindings['date_from'] = $filters['dateFrom'];
        }

        if (isset($filters['dateTo'])) {
            $sql .= ' AND created_at <= :date_to';
            $bindings['date_to'] = $filters['dateTo'];
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
        $bindings['limit'] = $perPage;
        $bindings['offset'] = ($page - 1) * $perPage;

        return $this->db->query($sql, $bindings)->map(self::hydrate(...));
    }

    public function save(Order $order): void
    {
        $this->db->execute(self::SQL_UPSERT, [
            'id' => $order->id,
            'tenant_id' => $order->tenantId,
            'order_number' => $order->orderNumber,
            'customer_id' => $order->customerId,
            'customer_email' => $order->customerEmail,
            'status' => $order->status->value,
            'subtotal' => $order->subtotal,
            'tax_amount' => $order->taxAmount,
            'discount_amount' => $order->discountAmount,
            'shipping_amount' => $order->shippingAmount,
            'shipping_method' => $order->shippingMethod?->value,
            'total' => $order->total,
            'amount_refunded' => $order->amountRefunded,
            'currency' => $order->currency,
            'payment_intent_id' => $order->paymentIntentId,
            'payment_status' => $order->paymentStatus->value,
            'billing_address' => json_encode($order->billingAddress, JSON_THROW_ON_ERROR),
            'shipping_address' => $order->shippingAddress !== null ? json_encode($order->shippingAddress, JSON_THROW_ON_ERROR) : null,
            'notes' => $order->notes,
            'data_classification' => $order->dataClassification->value,
            'created_at' => $order->createdAt->format('c'),
            'updated_at' => $order->updatedAt->format('c'),
        ]);
    }

    public function updateStatus(string $id, OrderStatus $status): void
    {
        $order = $this->findById($id);

        if ($order === null) {
            throw CmsException::orderNotFound($id);
        }

        OrderStatusStateMachine::transition($order->status, $status);

        $this->db->execute(self::SQL_UPDATE_STATUS, [
            'id' => $id,
            'new_status' => $status->value,
            'now' => new DateTimeImmutable()->format('c'),
        ]);
    }

    public function findByCustomer(string $customerId, int $page = 1, int $perPage = 20): array
    {
        return $this->db->query(self::SQL_FIND_BY_CUSTOMER, [
            'customer_id' => $customerId,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ])->map(self::hydrate(...));
    }

    private static function hydrate(Row $row): Order
    {
        /** @var array{line1: string, line2?: string, city: string, region?: string, postalCode: string, country: string} $billingAddress */
        $billingAddress = json_decode($row->getString('billing_address'), true, 512, JSON_THROW_ON_ERROR);

        $shippingRaw = $row->getNullableString('shipping_address');
        /** @var array{line1: string, line2?: string, city: string, region?: string, postalCode: string, country: string}|null $shippingAddress */
        $shippingAddress = $shippingRaw !== null ? json_decode($shippingRaw, true, 512, JSON_THROW_ON_ERROR) : null;

        return new Order(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            orderNumber: $row->getString('order_number'),
            customerId: $row->getString('customer_id'),
            customerEmail: $row->getString('customer_email'),
            status: OrderStatus::from($row->getString('status')),
            subtotal: $row->getInt('subtotal'),
            taxAmount: $row->getInt('tax_amount'),
            discountAmount: $row->getInt('discount_amount'),
            shippingAmount: $row->getInt('shipping_amount'),
            shippingMethod: $row->getNullableString('shipping_method') !== null
                ? ShippingMethod::from($row->getNullableString('shipping_method'))
                : null,
            total: $row->getInt('total'),
            amountRefunded: $row->getInt('amount_refunded'),
            currency: $row->getString('currency'),
            paymentIntentId: $row->getNullableString('payment_intent_id'),
            paymentStatus: PaymentStatus::from($row->getString('payment_status')),
            billingAddress: $billingAddress,
            shippingAddress: $shippingAddress,
            notes: $row->getNullableString('notes'),
            dataClassification: DataClassification::from($row->getString('data_classification')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }
}
