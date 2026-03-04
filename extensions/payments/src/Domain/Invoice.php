<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function bin2hex;
use function count;
use function random_bytes;

/**
 * Immutable invoice entity.
 *
 * Invoice numbers are sequential and immutable per EU legal requirements.
 * Once assigned, an invoice number cannot be reused or changed.
 *
 * Supports EN 16931 e-invoicing with seller/buyer party information,
 * per-line tax breakdown, and structured payment terms.
 *
 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
 */
#[Api(since: '1.0.0')]
final readonly class Invoice
{
    /**
     * @param list<InvoiceLineItem> $lineItems
     * @param list<InvoiceTaxBreakdown> $taxBreakdown
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $invoiceNumber,
        public string $customerId,
        public ?string $subscriptionId,
        public Money $subtotal,
        public Money $tax,
        public Money $total,
        public InvoiceStatus $status,
        public array $lineItems,
        public ?DateTimeImmutable $paidAt,
        public ?DateTimeImmutable $dueDate,
        public DateTimeImmutable $createdAt,
        public ?InvoiceParty $sellerParty = null,
        public ?InvoiceParty $buyerParty = null,
        public ?PaymentTerms $paymentTerms = null,
        public array $taxBreakdown = [],
        public array $metadata = [],
    ) {}

    /**
     * Create a new draft invoice.
     *
     * @param list<InvoiceLineItem> $lineItems
     * @param list<InvoiceTaxBreakdown> $taxBreakdown
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function create(
        string $invoiceNumber,
        string $customerId,
        array $lineItems,
        Money $tax,
        ?string $subscriptionId = null,
        ?DateTimeImmutable $dueDate = null,
        array $metadata = [],
        ?InvoiceParty $sellerParty = null,
        ?InvoiceParty $buyerParty = null,
        ?PaymentTerms $paymentTerms = null,
        array $taxBreakdown = [],
    ): self {
        $subtotal = self::calculateSubtotal($lineItems);

        return new self(
            id: bin2hex(random_bytes(16)),
            invoiceNumber: $invoiceNumber,
            customerId: $customerId,
            subscriptionId: $subscriptionId,
            subtotal: $subtotal,
            tax: $tax,
            total: $subtotal->add($tax),
            status: InvoiceStatus::Draft,
            lineItems: $lineItems,
            paidAt: null,
            dueDate: $dueDate,
            createdAt: new DateTimeImmutable(),
            sellerParty: $sellerParty,
            buyerParty: $buyerParty,
            paymentTerms: $paymentTerms,
            taxBreakdown: $taxBreakdown,
            metadata: $metadata,
        );
    }

    /**
     * Mark the invoice as paid.
     */
    #[NoDiscard]
    public function markPaid(): self
    {
        return clone($this, [
            'status' => InvoiceStatus::Paid,
            'paidAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Mark the invoice as voided.
     */
    #[NoDiscard]
    public function markVoided(): self
    {
        return clone($this, [
            'status' => InvoiceStatus::Voided,
        ]);
    }

    /**
     * Finalize the invoice (transition from draft to open).
     */
    #[NoDiscard]
    public function finalize(): self
    {
        return clone($this, [
            'status' => InvoiceStatus::Open,
        ]);
    }

    /**
     * @param list<InvoiceLineItem> $lineItems
     */
    private static function calculateSubtotal(array $lineItems): Money
    {
        if ($lineItems === []) {
            return Money::zero(Currency::USD);
        }

        $total = $lineItems[0]->total;

        for ($i = 1, $count = count($lineItems); $i < $count; $i++) {
            $total = $total->add($lineItems[$i]->total);
        }

        return $total;
    }
}
