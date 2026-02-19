<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\DataClassification;

/**
 * Order aggregate root representing a customer purchase.
 */
#[Api(since: '1.0.0')]
final readonly class Order
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $orderNumber Human-readable order reference
     * @param string $customerId UUIDv7 of the purchasing customer
     * @param string $customerEmail Customer email for order communication
     * @param OrderStatus $status Current order lifecycle status
     * @param int $subtotal Subtotal in minor currency units before tax/discounts
     * @param int $taxAmount Tax in minor currency units
     * @param int $discountAmount Total discount in minor currency units
     * @param int $shippingAmount Shipping cost in minor currency units
     * @param ShippingMethod|null $shippingMethod Shipping method used, null for digital-only
     * @param int $total Grand total in minor currency units
     * @param int $amountRefunded Cumulative refunded amount in minor currency units
     * @param string $currency ISO 4217 currency code
     * @param string|null $paymentIntentId External payment provider reference
     * @param PaymentStatus $paymentStatus Current payment processing status
     * @param array{line1: string, line2?: string, city: string, region?: string, postalCode: string, country: string} $billingAddress Billing address
     * @param array{line1: string, line2?: string, city: string, region?: string, postalCode: string, country: string}|null $shippingAddress Shipping address, null for digital-only orders
     * @param string|null $notes Customer or admin notes
     * @param DataClassification $dataClassification Data classification for compliance
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable $updatedAt Auto-managed update timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $orderNumber,
        public string $customerId,
        public string $customerEmail,
        public OrderStatus $status,
        public int $subtotal,
        public int $taxAmount,
        public int $discountAmount,
        public int $shippingAmount,
        public ?ShippingMethod $shippingMethod,
        public int $total,
        public int $amountRefunded,
        public string $currency,
        public ?string $paymentIntentId,
        public PaymentStatus $paymentStatus,
        public array $billingAddress,
        public ?array $shippingAddress,
        public ?string $notes,
        public DataClassification $dataClassification,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a new cart order.
     *
     * @param array{line1: string, line2?: string, city: string, region?: string, postalCode: string, country: string} $billingAddress
     */
    public static function create(
        string $id,
        string $orderNumber,
        string $customerId,
        string $customerEmail,
        string $currency,
        array $billingAddress,
        ?string $tenantId = null,
        DataClassification $dataClassification = DataClassification::Pii,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            tenantId: $tenantId,
            orderNumber: $orderNumber,
            customerId: $customerId,
            customerEmail: $customerEmail,
            status: OrderStatus::Cart,
            subtotal: 0,
            taxAmount: 0,
            discountAmount: 0,
            shippingAmount: 0,
            shippingMethod: null,
            total: 0,
            amountRefunded: 0,
            currency: $currency,
            paymentIntentId: null,
            paymentStatus: PaymentStatus::Pending,
            billingAddress: $billingAddress,
            shippingAddress: null,
            notes: null,
            dataClassification: $dataClassification,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function isPaid(): bool
    {
        return $this->paymentStatus->isPaid();
    }

    public function isFulfilled(): bool
    {
        return $this->status === OrderStatus::Fulfilled;
    }

    public function isCancelled(): bool
    {
        return $this->status === OrderStatus::Cancelled;
    }
}
