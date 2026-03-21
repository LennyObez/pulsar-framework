<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Invoice;

/**
 * Invoice rendering contract.
 * @api
 */
#[Api(since: '1.0.0')]
interface InvoiceRendererInterface
{
    /**
     * Render an invoice to the specified format.
     *
     * @return string Rendered content (HTML, PDF bytes, etc.)
     */
    public function render(Invoice $invoice): string;

    /**
     * The MIME type of the rendered output.
     */
    public function contentType(): string;
}
