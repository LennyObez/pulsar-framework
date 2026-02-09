<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Invoice;
use Pulsar\Extension\Cms\Commerce\InvoiceRendererInterface;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderItem;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Content\DataClassification;

#[CoversClass(Invoice::class)]
#[CoversClass(OrderItem::class)]
final class InvoiceRendererTest extends TestCase
{
    // ── HTML rendering with required fields ──────────────────────────

    #[Test]
    public function test_html_renderer_produces_required_fields(): void
    {
        $renderer = $this->createHtmlRenderer();
        $invoice = $this->createInvoice('INV-2026-0001');
        $order = $this->createOrder();
        $items = $this->createItems();

        $html = $renderer->render($invoice, $order, $items);

        self::assertStringContainsString('INV-2026-0001', $html);
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('Widget', $html);
        self::assertStringContainsString('2999', $html);
    }

    // ── Print CSS is included ───────────────────────────────────────

    #[Test]
    public function test_html_renderer_includes_print_css(): void
    {
        $renderer = $this->createHtmlRenderer();
        $invoice = $this->createInvoice('INV-2026-0002');
        $order = $this->createOrder();
        $items = $this->createItems();

        $html = $renderer->render($invoice, $order, $items);

        self::assertStringContainsString('@media print', $html);
    }

    // ── Contains total ──────────────────────────────────────────────

    #[Test]
    public function test_html_renderer_includes_total(): void
    {
        $renderer = $this->createHtmlRenderer();
        $invoice = $this->createInvoice('INV-2026-0003');
        $order = $this->createOrder();
        $items = $this->createItems();

        $html = $renderer->render($invoice, $order, $items);

        self::assertStringContainsString('3248', $html); // total including tax
    }

    private function createInvoice(string $number): Invoice
    {
        return new Invoice(
            id: 'inv-001',
            orderId: 'order-001',
            invoiceNumber: $number,
            issuedAt: new DateTimeImmutable(),
            dueAt: new DateTimeImmutable('+30 days'),
            pdfStoragePath: null,
            pdfHash: null,
            evidenceHash: 'evidence-hash-abc',
            dataClassification: DataClassification::Pii,
        );
    }

    private function createOrder(): Order
    {
        return new Order(
            id: 'order-001',
            tenantId: null,
            orderNumber: 'ORD-2026-0001',
            customerId: 'cust-001',
            customerEmail: 'customer@example.com',
            status: OrderStatus::Confirmed,
            subtotal: 2999,
            taxAmount: 249,
            discountAmount: 0,
            shippingAmount: 0,
            shippingMethod: null,
            total: 3248,
            amountRefunded: 0,
            currency: 'EUR',
            paymentIntentId: 'pi_test',
            paymentStatus: PaymentStatus::Paid,
            billingAddress: ['line1' => '123 Main St', 'city' => 'Brussels', 'postalCode' => '1000', 'country' => 'BE'],
            shippingAddress: null,
            notes: null,
            dataClassification: DataClassification::Pii,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * @return list<OrderItem>
     */
    private function createItems(): array
    {
        return [
            new OrderItem(
                id: 'item-001',
                orderId: 'order-001',
                productId: 'product-001',
                variantId: null,
                quantity: 1,
                unitPrice: 2999,
                totalPrice: 2999,
                taxAmount: 249,
                discountAmount: 0,
                productSnapshot: ['name' => 'Widget', 'sku' => 'WDG-001'],
            ),
        ];
    }

    private function createHtmlRenderer(): InvoiceRendererInterface
    {
        return new class implements InvoiceRendererInterface {
            public function render(Invoice $invoice, Order $order, array $items): string
            {
                $rows = '';

                foreach ($items as $item) {
                    /** @var string $name */
                    $name = $item->productSnapshot['name'] ?? 'Unknown';
                    $rows .= "<tr><td>{$name}</td><td>{$item->quantity}</td><td>{$item->unitPrice}</td><td>{$item->totalPrice}</td></tr>";
                }

                return <<<HTML
                    <html>
                    <head>
                    <style>@media print { body { font-size: 12pt; } }</style>
                    </head>
                    <body>
                    <h1>Invoice {$invoice->invoiceNumber}</h1>
                    <p>Order: {$order->orderNumber}</p>
                    <table><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>{$rows}</tbody></table>
                    <p>Total: {$order->total} {$order->currency}</p>
                    </body>
                    </html>
                    HTML;
            }
        };
    }
}
