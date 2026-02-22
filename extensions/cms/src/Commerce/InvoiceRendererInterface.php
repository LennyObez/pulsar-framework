<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Renders invoice data into a presentable format.
 */
#[Api(since: '1.0.0')]
interface InvoiceRendererInterface
{
    /**
     * Render an invoice as HTML.
     *
     * @param list<OrderItem> $items Order line items
     */
    public function render(Invoice $invoice, Order $order, array $items): string;
}
