<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * Contract for invoice generation.
 * @api
 */
#[Api(since: '1.0.0')]
interface InvoiceGeneratorInterface
{
    /**
     * Generate a new invoice with a sequential invoice number.
     *
     * @param list<InvoiceLineItem> $lineItems
     * @param array<string, mixed> $metadata
     */
    public function generate(
        string $customerId,
        ?string $subscriptionId,
        array $lineItems,
        Money $tax,
        ?DateTimeImmutable $dueDate = null,
        array $metadata = [],
    ): Invoice;
}
