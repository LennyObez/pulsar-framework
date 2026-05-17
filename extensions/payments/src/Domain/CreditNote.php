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
 * Immutable credit note (refund document) entity.
 *
 * Represents a legally compliant refund document that references
 * the original invoice. Credit note numbers follow the pattern
 * CN-YYYY-NNNNNN for sequential audit trails.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CreditNote
{
    /**
     * @param list<InvoiceLineItem> $lineItems
     * @param list<InvoiceTaxBreakdown> $taxBreakdown
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $creditNoteNumber,
        public string $originalInvoiceId,
        public string $customerId,
        public string $reason,
        public array $lineItems,
        public Money $subtotal,
        public Money $tax,
        public Money $total,
        public Currency $currency,
        public CreditNoteStatus $status,
        public ?InvoiceParty $sellerParty,
        public ?InvoiceParty $buyerParty,
        public array $taxBreakdown,
        public ?DateTimeImmutable $issuedAt,
        public DateTimeImmutable $createdAt,
        public array $metadata = [],
    ) {}

    /**
     * Create a new draft credit note referencing an invoice.
     *
     * @param list<InvoiceLineItem> $lineItems Credit line items (amounts being credited)
     * @param list<InvoiceTaxBreakdown> $taxBreakdown Tax breakdown for credited amounts
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function create(
        string $creditNoteNumber,
        string $originalInvoiceId,
        string $customerId,
        string $reason,
        array $lineItems,
        Money $tax,
        Currency $currency,
        ?InvoiceParty $sellerParty = null,
        ?InvoiceParty $buyerParty = null,
        array $taxBreakdown = [],
        array $metadata = [],
    ): self {
        $subtotal = self::calculateSubtotal($lineItems, $currency);

        return new self(
            id: bin2hex(random_bytes(16)),
            creditNoteNumber: $creditNoteNumber,
            originalInvoiceId: $originalInvoiceId,
            customerId: $customerId,
            reason: $reason,
            lineItems: $lineItems,
            subtotal: $subtotal,
            tax: $tax,
            total: $subtotal->add($tax),
            currency: $currency,
            status: CreditNoteStatus::Draft,
            sellerParty: $sellerParty,
            buyerParty: $buyerParty,
            taxBreakdown: $taxBreakdown,
            issuedAt: null,
            createdAt: new DateTimeImmutable(),
            metadata: $metadata,
        );
    }

    /**
     * Create a full credit note from an existing invoice.
     *
     * Credits all line items and the full tax amount from the original invoice.
     */
    #[NoDiscard]
    public static function fromInvoice(
        string $creditNoteNumber,
        Invoice $invoice,
        string $reason,
    ): self {
        return self::create(
            creditNoteNumber: $creditNoteNumber,
            originalInvoiceId: $invoice->id,
            customerId: $invoice->customerId,
            reason: $reason,
            lineItems: $invoice->lineItems,
            tax: $invoice->tax,
            currency: $invoice->subtotal->currency,
            sellerParty: $invoice->sellerParty,
            buyerParty: $invoice->buyerParty,
            taxBreakdown: $invoice->taxBreakdown,
        );
    }

    /**
     * Issue the credit note, making it official.
     */
    #[NoDiscard]
    public function issue(): static
    {
        return clone($this, [
            'status' => CreditNoteStatus::Issued,
            'issuedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Mark the credit note as applied to the customer's balance.
     */
    #[NoDiscard]
    public function apply(): static
    {
        return clone($this, [
            'status' => CreditNoteStatus::Applied,
        ]);
    }

    /**
     * @param list<InvoiceLineItem> $lineItems
     */
    private static function calculateSubtotal(array $lineItems, Currency $currency): Money
    {
        if ($lineItems === []) {
            return Money::zero($currency);
        }

        $total = $lineItems[0]->total;

        for ($i = 1, $count = count($lineItems); $i < $count; $i++) {
            $total = $total->add($lineItems[$i]->total);
        }

        return $total;
    }
}
