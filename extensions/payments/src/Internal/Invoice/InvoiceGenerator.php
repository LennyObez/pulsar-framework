<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Invoice;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\InvoiceGeneratorInterface;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Internal\Persistence\DbInvoiceRepository;

/**
 * Invoice generation service.
 *
 * Creates invoices with sequential, immutable invoice numbers.
 * Invoice numbering follows EU legal requirements: no gaps,
 * no reuse, chronologically ordered.
 */
#[Internal]
final readonly class InvoiceGenerator implements InvoiceGeneratorInterface
{
    public function __construct(
        private InvoiceNumbering $numbering,
        private DbInvoiceRepository $invoiceRepository,
    ) {}

    /**
     * Generate a new invoice.
     *
     * @param list<InvoiceLineItem> $lineItems
     * @param array<string, mixed> $metadata
     */
    #[Override]
    public function generate(
        string $customerId,
        ?string $subscriptionId,
        array $lineItems,
        Money $tax,
        ?DateTimeImmutable $dueDate = null,
        array $metadata = [],
    ): Invoice {
        $invoiceNumber = $this->numbering->next();

        $invoice = Invoice::create(
            invoiceNumber: $invoiceNumber,
            customerId: $customerId,
            lineItems: $lineItems,
            tax: $tax,
            subscriptionId: $subscriptionId,
            dueDate: $dueDate ?? new DateTimeImmutable()->modify('+30 days'),
            metadata: $metadata,
        );

        $this->invoiceRepository->save($invoice);

        return $invoice;
    }
}
