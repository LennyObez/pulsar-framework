<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use Pulsar\Api\Internal;
use Pulsar\Config\BusinessProfileProviderInterface;
use Pulsar\Extension\Cms\Commerce\Invoice;
use Pulsar\Extension\Cms\Commerce\InvoiceRendererInterface;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderItem;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;

use function is_scalar;
use function is_string;
use function number_format;
use function sprintf;

use const ENT_QUOTES;

/**
 * Renders invoices as styled HTML with print-optimized CSS.
 *
 * Seller information is sourced from the centralized BusinessProfile when available,
 * with fallback to CMS SettingsService for backward compatibility.
 */
#[Internal(reason: 'Use InvoiceRendererInterface for public API')]
final readonly class HtmlInvoiceRenderer implements InvoiceRendererInterface
{
    public function __construct(
        private ?SettingsServiceInterface $settings = null,
        private ?BusinessProfileProviderInterface $businessProfileProvider = null,
    ) {}

    public function render(Invoice $invoice, Order $order, array $items): string
    {
        $siteName = $this->resolveSellerName();
        $sellerAddress = $this->resolveSellerAddress();
        $sellerVatNumber = $this->resolveSellerVatNumber();

        $e = htmlspecialchars(...);

        $itemRows = '';

        foreach ($items as $item) {
            $productName = $this->extractProductName($item);
            $lineTotal = $item->totalPrice + $item->taxAmount - $item->discountAmount;

            $itemRows .= sprintf(
                '<tr><td>%s</td><td class="num">%d</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
                $e($productName, ENT_QUOTES, 'UTF-8'),
                $item->quantity,
                $this->formatAmount($item->unitPrice, $order->currency),
                $this->formatAmount($item->taxAmount, $order->currency),
                $this->formatAmount($lineTotal, $order->currency),
            );
        }

        $buyerAddress = $this->formatAddress($order->billingAddress);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Invoice {$e($invoice->invoiceNumber, ENT_QUOTES, 'UTF-8')}</title>
            <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; color: #1a1a1a; line-height: 1.5; padding: 2rem; }
            .invoice { max-width: 800px; margin: 0 auto; }
            .header { display: flex; justify-content: space-between; margin-bottom: 2rem; border-bottom: 2px solid #1a1a1a; padding-bottom: 1rem; }
            .header h1 { font-size: 1.5rem; }
            .meta { margin-bottom: 2rem; }
            .meta dt { font-weight: 600; display: inline; }
            .meta dd { display: inline; margin-right: 1.5rem; }
            .parties { display: flex; justify-content: space-between; margin-bottom: 2rem; }
            .parties section { width: 48%; }
            .parties h2 { font-size: 0.9rem; text-transform: uppercase; color: #666; margin-bottom: 0.5rem; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
            th, td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid #ddd; }
            th { background: #f5f5f5; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; }
            .num { text-align: right; font-variant-numeric: tabular-nums; }
            .totals { width: 300px; margin-left: auto; }
            .totals td { border-bottom: none; padding: 0.3rem 0.75rem; }
            .totals .grand-total td { border-top: 2px solid #1a1a1a; font-weight: 700; font-size: 1.1rem; }
            .status { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 4px; font-weight: 600; font-size: 0.85rem; }
            .status--paid { background: #d4edda; color: #155724; }
            .status--pending { background: #fff3cd; color: #856404; }
            .footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #ddd; font-size: 0.8rem; color: #666; }
            .print-btn { display: block; margin: 1rem auto; padding: 0.5rem 2rem; background: #1a1a1a; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9rem; }
            @media print {
                body { padding: 0; }
                .print-btn { display: none; }
                .invoice { max-width: none; }
            }
            </style>
            </head>
            <body>
            <div class="invoice">
                <div class="header">
                    <div>
                        <h1>{$e($siteName, ENT_QUOTES, 'UTF-8')}</h1>
                        <p>{$e($sellerAddress, ENT_QUOTES, 'UTF-8')}</p>
                        {$this->renderVatNumber($sellerVatNumber)}
                    </div>
                    <div>
                        <h1>Invoice</h1>
                        <p><strong>{$e($invoice->invoiceNumber, ENT_QUOTES, 'UTF-8')}</strong></p>
                    </div>
                </div>

                <dl class="meta">
                    <dt>Invoice Date:</dt><dd>{$invoice->issuedAt->format('Y-m-d')}</dd>
                    <dt>Due Date:</dt><dd>{$invoice->dueAt->format('Y-m-d')}</dd>
                    <dt>Order:</dt><dd>{$e($order->orderNumber, ENT_QUOTES, 'UTF-8')}</dd>
                    <dt>Payment:</dt><dd><span class="status status--{$order->paymentStatus->value}">{$e($order->paymentStatus->label(), ENT_QUOTES, 'UTF-8')}</span></dd>
                </dl>

                <div class="parties">
                    <section>
                        <h2>From</h2>
                        <p>{$e($siteName, ENT_QUOTES, 'UTF-8')}</p>
                        <p>{$e($sellerAddress, ENT_QUOTES, 'UTF-8')}</p>
                    </section>
                    <section>
                        <h2>Bill To</h2>
                        <p>{$e($order->customerEmail, ENT_QUOTES, 'UTF-8')}</p>
                        $buyerAddress
                    </section>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="num">Qty</th>
                            <th class="num">Unit Price</th>
                            <th class="num">Tax</th>
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        $itemRows
                    </tbody>
                </table>

                <table class="totals">
                    <tr><td>Subtotal</td><td class="num">{$this->formatAmount($order->subtotal, $order->currency)}</td></tr>
                    {$this->renderDiscountRow($order)}
                    <tr><td>Tax</td><td class="num">{$this->formatAmount($order->taxAmount, $order->currency)}</td></tr>
                    <tr class="grand-total"><td>Total</td><td class="num">{$this->formatAmount($order->total, $order->currency)}</td></tr>
                </table>

                <div class="footer">
                    <p>Invoice #{$e($invoice->invoiceNumber, ENT_QUOTES, 'UTF-8')} &mdash; Generated on {$invoice->issuedAt->format('c')} UTC</p>
                    <p>Evidence Hash: {$e($invoice->evidenceHash ?? '', ENT_QUOTES, 'UTF-8')}</p>
                </div>

                <button class="print-btn" onclick="window.print()">Print / Save as PDF</button>
            </div>
            </body>
            </html>
            HTML;
    }

    private function formatAmount(int $minorUnits, string $currency): string
    {
        $major = number_format($minorUnits / 100, 2);

        return $currency . ' ' . $major;
    }

    /**
     * @param array<string, mixed> $address
     */
    private function formatAddress(array $address): string
    {
        $e = htmlspecialchars(...);
        $lines = [];

        $line1 = is_string($address['line1'] ?? null) ? $address['line1'] : '';
        $lines[] = $e($line1, ENT_QUOTES, 'UTF-8');

        $line2 = is_string($address['line2'] ?? null) ? $address['line2'] : '';

        if ($line2 !== '') {
            $lines[] = $e($line2, ENT_QUOTES, 'UTF-8');
        }

        $city = is_string($address['city'] ?? null) ? $address['city'] : '';
        $cityLine = $e($city, ENT_QUOTES, 'UTF-8');

        $region = is_string($address['region'] ?? null) ? $address['region'] : '';

        if ($region !== '') {
            $cityLine .= ', ' . $e($region, ENT_QUOTES, 'UTF-8');
        }

        $postalCode = is_string($address['postalCode'] ?? null) ? $address['postalCode'] : '';
        $cityLine .= ' ' . $e($postalCode, ENT_QUOTES, 'UTF-8');
        $lines[] = $cityLine;

        $country = is_string($address['country'] ?? null) ? $address['country'] : '';
        $lines[] = $e($country, ENT_QUOTES, 'UTF-8');

        return '<p>' . implode('<br>', $lines) . '</p>';
    }

    private function extractProductName(OrderItem $item): string
    {
        $sku = $item->productSnapshot['sku'] ?? null;

        return is_string($sku) ? $sku : 'Product ' . $item->productId;
    }

    private function renderVatNumber(string $vatNumber): string
    {
        if ($vatNumber === '') {
            return '';
        }

        $e = htmlspecialchars(...);

        return '<p>VAT: ' . $e($vatNumber, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    private function renderDiscountRow(Order $order): string
    {
        if ($order->discountAmount === 0) {
            return '';
        }

        return '<tr><td>Discount</td><td class="num">-' . $this->formatAmount($order->discountAmount, $order->currency) . '</td></tr>';
    }

    private function getSetting(string $group, string $key): ?string
    {
        if ($this->settings === null) {
            return null;
        }

        $value = $this->settings->get($group, $key);

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);
    }

    /**
     * Resolve seller name from BusinessProfile, falling back to CMS settings.
     */
    private function resolveSellerName(): string
    {
        if ($this->businessProfileProvider !== null) {
            $profile = $this->businessProfileProvider->getProfile();

            if ($profile->companyName !== '') {
                return $profile->displayName();
            }
        }

        return $this->getSetting('general', 'site_name') ?? 'Store';
    }

    /**
     * Resolve seller address from BusinessProfile, falling back to CMS settings.
     */
    private function resolveSellerAddress(): string
    {
        if ($this->businessProfileProvider !== null) {
            $profile = $this->businessProfileProvider->getProfile();

            if ($profile->addressLine1 !== null) {
                return $profile->formattedAddress();
            }
        }

        return $this->getSetting('commerce', 'seller_address') ?? '';
    }

    /**
     * Resolve seller VAT number from BusinessProfile, falling back to CMS settings.
     */
    private function resolveSellerVatNumber(): string
    {
        if ($this->businessProfileProvider !== null) {
            $profile = $this->businessProfileProvider->getProfile();

            if ($profile->vatNumber !== null) {
                return $profile->vatNumber;
            }
        }

        return $this->getSetting('commerce', 'seller_vat_number') ?? '';
    }
}
