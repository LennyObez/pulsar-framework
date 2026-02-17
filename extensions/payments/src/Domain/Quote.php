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
 * Immutable quote (estimate/proforma) entity.
 *
 * Represents a commercial proposal that can be accepted by the customer
 * and converted into a binding Invoice. Quote numbers follow the
 * pattern QTE-YYYY-NNNNNN for traceability.
 *
 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
 */
#[Api(since: '1.0.0')]
final readonly class Quote
{
    /**
     * @param list<InvoiceLineItem> $lineItems
     * @param list<InvoiceTaxBreakdown> $taxBreakdown
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $quoteNumber,
        public string $customerId,
        public QuoteStatus $status,
        public array $lineItems,
        public Money $subtotal,
        public Money $tax,
        public Money $total,
        public Currency $currency,
        public ?DateTimeImmutable $validUntil,
        public ?string $notes,
        public ?InvoiceParty $sellerParty,
        public ?InvoiceParty $buyerParty,
        public ?PaymentTerms $paymentTerms,
        public array $taxBreakdown,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $metadata = [],
    ) {}

    /**
     * Create a new draft quote.
     *
     * @param list<InvoiceLineItem> $lineItems
     * @param list<InvoiceTaxBreakdown> $taxBreakdown
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function create(
        string $quoteNumber,
        string $customerId,
        array $lineItems,
        Money $tax,
        Currency $currency,
        ?DateTimeImmutable $validUntil = null,
        ?string $notes = null,
        ?InvoiceParty $sellerParty = null,
        ?InvoiceParty $buyerParty = null,
        ?PaymentTerms $paymentTerms = null,
        array $taxBreakdown = [],
        array $metadata = [],
    ): self {
        $subtotal = self::calculateSubtotal($lineItems, $currency);
        $now = new DateTimeImmutable();

        return new self(
            id: bin2hex(random_bytes(16)),
            quoteNumber: $quoteNumber,
            customerId: $customerId,
            status: QuoteStatus::Draft,
            lineItems: $lineItems,
            subtotal: $subtotal,
            tax: $tax,
            total: $subtotal->add($tax),
            currency: $currency,
            validUntil: $validUntil,
            notes: $notes,
            sellerParty: $sellerParty,
            buyerParty: $buyerParty,
            paymentTerms: $paymentTerms,
            taxBreakdown: $taxBreakdown,
            createdAt: $now,
            updatedAt: $now,
            metadata: $metadata,
        );
    }

    /**
     * Mark the quote as sent to the customer.
     */
    #[NoDiscard]
    public function send(): self
    {
        return clone($this, [
            'status' => QuoteStatus::Sent,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Accept the quote. Only possible from Draft or Sent status.
     */
    #[NoDiscard]
    public function accept(): self
    {
        return clone($this, [
            'status' => QuoteStatus::Accepted,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Reject the quote.
     */
    #[NoDiscard]
    public function reject(): self
    {
        return clone($this, [
            'status' => QuoteStatus::Rejected,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Expire the quote (e.g., validUntil date has passed).
     */
    #[NoDiscard]
    public function expire(): self
    {
        return clone($this, [
            'status' => QuoteStatus::Expired,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Convert this accepted quote into an Invoice.
     *
     * The resulting invoice references this quote via metadata and
     * preserves all line items, tax breakdown, parties, and payment terms.
     *
     * @param string $invoiceNumber The invoice number to assign (from InvoiceNumbering)
     * @param DateTimeImmutable|null $dueDate Payment due date (defaults to payment terms netDays from now)
     */
    #[NoDiscard]
    public function toInvoice(string $invoiceNumber, ?DateTimeImmutable $dueDate = null): Invoice
    {
        $effectiveDueDate = $dueDate;

        if ($effectiveDueDate === null && $this->paymentTerms !== null) {
            /** @var DateTimeImmutable $effectiveDueDate */
            $effectiveDueDate = new DateTimeImmutable()->modify('+' . $this->paymentTerms->netDays . ' days');
        }

        $metadata = $this->metadata;
        $metadata['quote_id'] = $this->id;
        $metadata['quote_number'] = $this->quoteNumber;

        return Invoice::create(
            invoiceNumber: $invoiceNumber,
            customerId: $this->customerId,
            lineItems: $this->lineItems,
            tax: $this->tax,
            dueDate: $effectiveDueDate,
            metadata: $metadata,
            sellerParty: $this->sellerParty,
            buyerParty: $this->buyerParty,
            paymentTerms: $this->paymentTerms,
            taxBreakdown: $this->taxBreakdown,
        );
    }

    /**
     * Whether the quote has expired based on its validUntil date.
     */
    public function isExpired(): bool
    {
        if ($this->validUntil === null) {
            return false;
        }

        return $this->validUntil < new DateTimeImmutable();
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
