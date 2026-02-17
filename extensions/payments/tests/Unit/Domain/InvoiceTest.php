<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\InvoiceStatus;
use Pulsar\Extension\Payments\Domain\Money;

use function strlen;

final class InvoiceTest extends TestCase
{
    #[Test]
    public function createBuildsInvoiceWithCorrectSubtotal(): void
    {
        $lineItems = [
            InvoiceLineItem::create('Widget A', 2, Money::of(1000, Currency::USD)),
            InvoiceLineItem::create('Widget B', 1, Money::of(500, Currency::USD)),
        ];
        $tax = Money::of(500, Currency::USD);

        $invoice = Invoice::create('INV-001', 'cust-1', $lineItems, $tax);

        self::assertSame('INV-001', $invoice->invoiceNumber);
        self::assertSame('cust-1', $invoice->customerId);
        self::assertSame(2500, $invoice->subtotal->amount);
        self::assertSame(500, $invoice->tax->amount);
        self::assertSame(3000, $invoice->total->amount);
        self::assertSame(InvoiceStatus::Draft, $invoice->status);
        self::assertNull($invoice->paidAt);
        self::assertNull($invoice->subscriptionId);
        self::assertCount(2, $invoice->lineItems);
    }

    #[Test]
    public function createWithEmptyLineItemsUsesZeroSubtotal(): void
    {
        $tax = Money::of(0, Currency::USD);
        $invoice = Invoice::create('INV-002', 'cust-2', [], $tax);

        self::assertSame(0, $invoice->subtotal->amount);
        self::assertSame(Currency::USD, $invoice->subtotal->currency);
        self::assertSame(0, $invoice->total->amount);
    }

    #[Test]
    public function createWithOptionalParams(): void
    {
        $lineItems = [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))];
        $tax = Money::of(200, Currency::EUR);
        $dueDate = new DateTimeImmutable('+30 days');

        $invoice = Invoice::create(
            invoiceNumber: 'INV-003',
            customerId: 'cust-3',
            lineItems: $lineItems,
            tax: $tax,
            subscriptionId: 'sub-1',
            dueDate: $dueDate,
            metadata: ['ref' => 'test'],
        );

        self::assertSame('sub-1', $invoice->subscriptionId);
        self::assertSame($dueDate, $invoice->dueDate);
        self::assertSame(['ref' => 'test'], $invoice->metadata);
    }

    #[Test]
    public function markPaidTransitionsStatus(): void
    {
        $invoice = $this->createSampleInvoice();
        $paid = $invoice->markPaid();

        self::assertSame(InvoiceStatus::Paid, $paid->status);
        self::assertNotNull($paid->paidAt);
        self::assertSame(InvoiceStatus::Draft, $invoice->status, 'original is immutable');
    }

    #[Test]
    public function markVoidedTransitionsStatus(): void
    {
        $invoice = $this->createSampleInvoice();
        $voided = $invoice->markVoided();

        self::assertSame(InvoiceStatus::Voided, $voided->status);
        self::assertSame(InvoiceStatus::Draft, $invoice->status);
    }

    #[Test]
    public function finalizeTransitionsToOpen(): void
    {
        $invoice = $this->createSampleInvoice();
        $finalized = $invoice->finalize();

        self::assertSame(InvoiceStatus::Open, $finalized->status);
    }

    #[Test]
    public function invoiceIdIsGenerated(): void
    {
        $invoice = $this->createSampleInvoice();

        self::assertNotEmpty($invoice->id);
        self::assertSame(32, strlen($invoice->id));
    }

    private function createSampleInvoice(): Invoice
    {
        return Invoice::create(
            'INV-100',
            'cust-x',
            [InvoiceLineItem::create('Service', 1, Money::of(5000, Currency::USD))],
            Money::of(1000, Currency::USD),
        );
    }
}
