<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Service interface for invoice generation and retrieval.
 * @api
 */
#[Api(since: '1.0.0')]
interface InvoiceServiceInterface
{
    /**
     * Generate an invoice for a completed order.
     */
    public function generate(string $orderId): Invoice;

    /**
     * Get the rendered HTML content of an invoice.
     */
    public function getInvoiceHtml(string $invoiceId): string;
}
