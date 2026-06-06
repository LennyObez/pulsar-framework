<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Invoice;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\InvoiceRendererInterface;
use Pulsar\Extension\Payments\Domain\Invoice;

use function htmlspecialchars;
use function sprintf;

/**
 * HTML-based invoice renderer.
 *
 * Renders invoices as clean, printable HTML that can be converted
 * to PDF using a headless browser or wkhtmltopdf.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class PdfInvoiceRenderer implements InvoiceRendererInterface
{
    #[Override]
    public function render(Invoice $invoice): string
    {
        $rows = '';

        foreach ($invoice->lineItems as $item) {
            $desc = htmlspecialchars($item->description, ENT_QUOTES | ENT_HTML5);
            $rows .= sprintf(
                '<tr><td>%s</td><td>%d</td><td>%s %s</td><td>%s %s</td></tr>',
                $desc,
                $item->quantity,
                $item->unitPrice->currency->symbol(),
                $item->unitPrice->format(),
                $item->total->currency->symbol(),
                $item->total->format(),
            );
        }

        $invoiceNumber = htmlspecialchars($invoice->invoiceNumber, ENT_QUOTES | ENT_HTML5);
        $date = $invoice->createdAt->format('Y-m-d');
        $dueDate = $invoice->dueDate?->format('Y-m-d') ?? '-';
        $subtotal = $invoice->subtotal->currency->symbol() . ' ' . $invoice->subtotal->format();
        $tax = $invoice->tax->currency->symbol() . ' ' . $invoice->tax->format();
        $total = $invoice->total->currency->symbol() . ' ' . $invoice->total->format();
        $status = htmlspecialchars($invoice->status->value, ENT_QUOTES | ENT_HTML5);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>Invoice {$invoiceNumber}</title>
                <style>
                    body { font-family: -apple-system, system-ui, sans-serif; margin: 2rem; color: #0f172a; }
                    .header { display: flex; justify-content: space-between; margin-bottom: 2rem; }
                    .invoice-number { font-size: 1.5rem; font-weight: 700; }
                    table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
                    th, td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #e2e8f0; }
                    th { background: #f8fafc; font-weight: 600; }
                    .totals { width: 300px; margin-left: auto; }
                    .totals td:first-child { font-weight: 600; }
                    .totals .total-row { font-size: 1.125rem; border-top: 2px solid #0f172a; }
                    .status { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 600; }
                    .status-paid { background: rgba(16, 185, 129, 0.1); color: #059669; }
                    .status-open { background: rgba(245, 158, 11, 0.1); color: #d97706; }
                    .status-draft { background: rgba(100, 116, 139, 0.1); color: #475569; }
                </style>
            </head>
            <body>
                <div class="header">
                    <div>
                        <div class="invoice-number">{$invoiceNumber}</div>
                        <div>Date: {$date}</div>
                        <div>Due: {$dueDate}</div>
                    </div>
                    <div>
                        <span class="status status-{$status}">{$status}</span>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
                <table class="totals">
                    <tr><td>Subtotal</td><td>{$subtotal}</td></tr>
                    <tr><td>Tax</td><td>{$tax}</td></tr>
                    <tr class="total-row"><td>Total</td><td>{$total}</td></tr>
                </table>
            </body>
            </html>
            HTML;
    }

    #[Override]
    public function contentType(): string
    {
        return 'text/html';
    }
}
