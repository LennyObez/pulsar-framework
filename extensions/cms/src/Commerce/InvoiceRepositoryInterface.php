<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Repository interface for invoice persistence.
 * @api
 */
#[Api(since: '1.0.0')]
interface InvoiceRepositoryInterface
{
    public function findById(string $id): ?Invoice;

    public function findByOrder(string $orderId): ?Invoice;

    public function save(Invoice $invoice): void;

    /**
     * Generate the next sequential invoice number.
     */
    public function nextInvoiceNumber(?string $tenantId = null): string;
}
