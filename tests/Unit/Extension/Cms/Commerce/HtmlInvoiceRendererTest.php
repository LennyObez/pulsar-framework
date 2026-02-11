<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Invoice;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderItem;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Internal\Commerce\HtmlInvoiceRenderer;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;

#[CoversClass(HtmlInvoiceRenderer::class)]
final class HtmlInvoiceRendererTest extends TestCase
{
    #[Test]
    public function renderProducesValidHtml(): void
    {
        $renderer = new HtmlInvoiceRenderer();
        $invoice = $this->buildInvoice();
        $order = $this->buildOrder();
        $items = [$this->buildOrderItem()];

        $html = $renderer->render($invoice, $order, $items);

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('INV-001', $html);
        self::assertStringContainsString('ORD-001', $html);
        self::assertStringContainsString('test@example.com', $html);
    }

    #[Test]
    public function renderFormatsAmountsCorrectly(): void
    {
        $renderer = new HtmlInvoiceRenderer();
        $invoice = $this->buildInvoice();
        $order = $this->buildOrder();
        $items = [$this->buildOrderItem()];

        $html = $renderer->render($invoice, $order, $items);

        // subtotal = 5000 cents = usd 50.00
        self::assertStringContainsString('usd 50.00', $html);
        // tax = 500 cents = usd 5.00
        self::assertStringContainsString('usd 5.00', $html);
    }

    #[Test]
    public function renderIncludesItemRows(): void
    {
        $renderer = new HtmlInvoiceRenderer();
        $invoice = $this->buildInvoice();
        $order = $this->buildOrder();
        $items = [
            $this->buildOrderItem(sku: 'WIDGET-A'),
            $this->buildOrderItem(productId: 'p2', sku: 'GADGET-B'),
        ];

        $html = $renderer->render($invoice, $order, $items);

        self::assertStringContainsString('WIDGET-A', $html);
        self::assertStringContainsString('GADGET-B', $html);
    }

    #[Test]
    public function renderUsesProductIdFallbackWhenNoSku(): void
    {
        $renderer = new HtmlInvoiceRenderer();
        $invoice = $this->buildInvoice();
        $order = $this->buildOrder();
        $items = [$this->buildOrderItem(sku: null)];

        $html = $renderer->render($invoice, $order, $items);

        self::assertStringContainsString('Product p1', $html);
    }

    #[Test]
    public function renderShowsDiscountRowWhenPresent(): void
    {
        $renderer = new HtmlInvoiceRenderer();
        $invoice = $this->buildInvoice();
        $order = $this->buildOrder(discountAmount: 1000);
        $items = [$this->buildOrderItem()];

        $html = $renderer->render($invoice, $order, $items);

        self::assertStringContainsString('Discount', $html);
        self::assertStringContainsString('usd 10.00', $html);
    }

    #[Test]
    public function renderHidesDiscountRowWhenZero(): void
    {
        $renderer = new HtmlInvoiceRenderer();
        $invoice = $this->buildInvoice();
        $order = $this->buildOrder(discountAmount: 0);
        $items = [$this->buildOrderItem()];

        $html = $renderer->render($invoice, $order, $items);

        // "Discount" should not appear in totals section
        self::assertStringNotContainsString('Discount</td>', $html);
    }

    #[Test]
    public function renderIncludesEvidenceHash(): void
    {
        $renderer = new HtmlInvoiceRenderer();
        $invoice = $this->buildInvoice(evidenceHash: 'abc123hash');
        $order = $this->buildOrder();
        $items = [$this->buildOrderItem()];

        $html = $renderer->render($invoice, $order, $items);

        self::assertStringContainsString('Evidence Hash:', $html);
        self::assertStringContainsString('abc123hash', $html);
    }

    #[Test]
    public function renderWithSettingsUsesStoreName(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn(string $group, string $key): ?string => match ([$group, $key]) {
                ['general', 'site_name'] => 'My Shop',
                ['commerce', 'seller_address'] => '123 Commerce St',
                ['commerce', 'seller_vat_number'] => 'VAT123',
                default => null,
            },
        );

        $renderer = new HtmlInvoiceRenderer($settings);
        $invoice = $this->buildInvoice();
        $order = $this->buildOrder();
        $items = [$this->buildOrderItem()];

        $html = $renderer->render($invoice, $order, $items);

        self::assertStringContainsString('My Shop', $html);
        self::assertStringContainsString('123 Commerce St', $html);
        self::assertStringContainsString('VAT123', $html);
    }

    #[Test]
    public function renderWithoutSettingsUsesDefaults(): void
    {
        $renderer = new HtmlInvoiceRenderer();
        $html = $renderer->render($this->buildInvoice(), $this->buildOrder(), [$this->buildOrderItem()]);

        self::assertStringContainsString('Store', $html);
    }

    #[Test]
    public function renderIncludesBillingAddress(): void
    {
        $renderer = new HtmlInvoiceRenderer();
        $order = $this->buildOrder();
        $html = $renderer->render($this->buildInvoice(), $order, [$this->buildOrderItem()]);

        self::assertStringContainsString('123 Main St', $html);
        self::assertStringContainsString('Springfield', $html);
        self::assertStringContainsString('62701', $html);
    }

    private function buildInvoice(?string $evidenceHash = null): Invoice
    {
        $now = new DateTimeImmutable();

        return new Invoice(
            id: 'inv-1',
            orderId: 'ord-1',
            invoiceNumber: 'INV-001',
            issuedAt: $now,
            dueAt: $now->modify('+30 days'),
            pdfStoragePath: null,
            pdfHash: null,
            evidenceHash: $evidenceHash,
            dataClassification: DataClassification::Pii,
        );
    }

    private function buildOrder(int $discountAmount = 0): Order
    {
        $now = new DateTimeImmutable();

        return new Order(
            id: 'ord-1',
            tenantId: null,
            orderNumber: 'ORD-001',
            customerId: 'cust-1',
            customerEmail: 'test@example.com',
            status: OrderStatus::Confirmed,
            subtotal: 5000,
            taxAmount: 500,
            discountAmount: $discountAmount,
            shippingAmount: 0,
            shippingMethod: ShippingMethod::Standard,
            total: 5500 - $discountAmount,
            amountRefunded: 0,
            currency: 'usd',
            paymentIntentId: null,
            paymentStatus: PaymentStatus::Paid,
            billingAddress: [
                'line1' => '123 Main St',
                'city' => 'Springfield',
                'postalCode' => '62701',
                'country' => 'US',
            ],
            shippingAddress: null,
            notes: '',
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function buildOrderItem(string $productId = 'p1', ?string $sku = 'WIDGET-A'): OrderItem
    {
        $snapshot = $sku !== null ? ['sku' => $sku] : [];

        return new OrderItem(
            id: 'item-1',
            orderId: 'ord-1',
            productId: $productId,
            variantId: null,
            quantity: 2,
            unitPrice: 1000,
            totalPrice: 2000,
            taxAmount: 200,
            discountAmount: 0,
            productSnapshot: $snapshot,
        );
    }
}
