<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when an invoice is generated for an order.
 */
#[Api(since: '1.0.0')]
final readonly class InvoiceGenerated
{
    public function __construct(
        public string $invoiceId,
        public string $orderId,
        public string $invoiceNumber,
    ) {}
}
