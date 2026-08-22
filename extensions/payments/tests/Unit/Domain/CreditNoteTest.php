<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\CreditNote;
use Pulsar\Extension\Payments\Domain\CreditNoteStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\InvoiceParty;
use Pulsar\Extension\Payments\Domain\InvoiceTaxBreakdown;
use Pulsar\Extension\Payments\Domain\Money;

use function strlen;

final class CreditNoteTest extends TestCase
{
    #[Test]
    public function createBuildsDraftCreditNote(): void
    {
        $lineItems = [
            InvoiceLineItem::create('Refund: Widget A', 1, Money::of(2000, Currency::EUR)),
        ];
        $tax = Money::of(420, Currency::EUR);

        $cn = CreditNote::create(
            creditNoteNumber: 'CN-2026-000001',
            originalInvoiceId: 'inv-abc',
            customerId: 'cust-42',
            reason: 'Defective product',
            lineItems: $lineItems,
            tax: $tax,
            currency: Currency::EUR,
        );

        self::assertSame('CN-2026-000001', $cn->creditNoteNumber);
        self::assertSame('inv-abc', $cn->originalInvoiceId);
        self::assertSame('cust-42', $cn->customerId);
        self::assertSame('Defective product', $cn->reason);
        self::assertSame(CreditNoteStatus::Draft, $cn->status);
        self::assertSame(2000, $cn->subtotal->amount);
        self::assertSame(420, $cn->tax->amount);
        self::assertSame(2420, $cn->total->amount);
        self::assertSame(Currency::EUR, $cn->currency);
        self::assertNull($cn->issuedAt);
        self::assertCount(1, $cn->lineItems);
        self::assertSame(32, strlen($cn->id));
    }

    #[Test]
    public function createWithEmptyLineItemsUsesZero(): void
    {
        $cn = CreditNote::create(
            creditNoteNumber: 'CN-2026-000002',
            originalInvoiceId: 'inv-def',
            customerId: 'cust-1',
            reason: 'Goodwill',
            lineItems: [],
            tax: Money::of(0, Currency::USD),
            currency: Currency::USD,
        );

        self::assertSame(0, $cn->subtotal->amount);
        self::assertSame(0, $cn->total->amount);
    }

    #[Test]
    public function fromInvoiceCreatesFullCreditNote(): void
    {
        $seller = $this->createParty('Seller Corp');
        $buyer = $this->createParty('Buyer Ltd');
        $breakdown = [
            new InvoiceTaxBreakdown('S', 2100, Money::of(10000, Currency::EUR), Money::of(2100, Currency::EUR), 'BE'),
        ];

        $invoice = Invoice::create(
            invoiceNumber: 'INV-2026-000010',
            customerId: 'cust-10',
            lineItems: [
                InvoiceLineItem::create('Service A', 2, Money::of(5000, Currency::EUR)),
            ],
            tax: Money::of(2100, Currency::EUR),
            sellerParty: $seller,
            buyerParty: $buyer,
            taxBreakdown: $breakdown,
        );

        $cn = CreditNote::fromInvoice('CN-2026-000010', $invoice, 'Full refund');

        self::assertSame($invoice->id, $cn->originalInvoiceId);
        self::assertSame($invoice->customerId, $cn->customerId);
        self::assertSame('Full refund', $cn->reason);
        self::assertSame($invoice->subtotal->amount, $cn->subtotal->amount);
        self::assertSame($invoice->tax->amount, $cn->tax->amount);
        self::assertSame($invoice->total->amount, $cn->total->amount);
        self::assertSame($seller, $cn->sellerParty);
        self::assertSame($buyer, $cn->buyerParty);
        self::assertCount(1, $cn->taxBreakdown);
        self::assertCount(1, $cn->lineItems);
    }

    #[Test]
    public function issueTransitionsToIssuedStatusWithTimestamp(): void
    {
        $cn = $this->createSampleCreditNote();
        $issued = $cn->issue();

        self::assertSame(CreditNoteStatus::Issued, $issued->status);
        self::assertNotNull($issued->issuedAt);
        self::assertSame(CreditNoteStatus::Draft, $cn->status, 'original is immutable');
        self::assertNull($cn->issuedAt);
    }

    #[Test]
    public function applyTransitionsToAppliedStatus(): void
    {
        $cn = $this->createSampleCreditNote()->issue();
        $applied = $cn->apply();

        self::assertSame(CreditNoteStatus::Applied, $applied->status);
        self::assertSame(CreditNoteStatus::Issued, $cn->status, 'original is immutable');
    }

    #[Test]
    public function partialCreditNoteCreditsSubsetOfLineItems(): void
    {
        $cn = CreditNote::create(
            creditNoteNumber: 'CN-2026-000003',
            originalInvoiceId: 'inv-ghi',
            customerId: 'cust-5',
            reason: 'Partial refund: 1 of 3 items',
            lineItems: [
                InvoiceLineItem::create('Widget A', 1, Money::of(3000, Currency::USD)),
            ],
            tax: Money::of(630, Currency::USD),
            currency: Currency::USD,
        );

        self::assertSame(3000, $cn->subtotal->amount);
        self::assertSame(3630, $cn->total->amount);
        self::assertCount(1, $cn->lineItems);
    }

    #[Test]
    public function statusLifecyclePreservesImmutability(): void
    {
        $draft = $this->createSampleCreditNote();
        $issued = $draft->issue();
        $applied = $issued->apply();

        self::assertSame(CreditNoteStatus::Draft, $draft->status);
        self::assertSame(CreditNoteStatus::Issued, $issued->status);
        self::assertSame(CreditNoteStatus::Applied, $applied->status);
        self::assertNotSame($draft, $issued);
        self::assertNotSame($issued, $applied);
    }

    #[Test]
    public function createWithSellerBuyerAndTaxBreakdown(): void
    {
        $seller = $this->createParty('Refunder Corp');
        $buyer = $this->createParty('Customer Ltd');
        $breakdown = [
            new InvoiceTaxBreakdown('S', 2100, Money::of(5000, Currency::EUR), Money::of(1050, Currency::EUR), 'BE'),
        ];

        $cn = CreditNote::create(
            creditNoteNumber: 'CN-2026-000004',
            originalInvoiceId: 'inv-jkl',
            customerId: 'cust-6',
            reason: 'Service not rendered',
            lineItems: [InvoiceLineItem::create('Service', 1, Money::of(5000, Currency::EUR))],
            tax: Money::of(1050, Currency::EUR),
            currency: Currency::EUR,
            sellerParty: $seller,
            buyerParty: $buyer,
            taxBreakdown: $breakdown,
        );

        self::assertSame($seller, $cn->sellerParty);
        self::assertSame($buyer, $cn->buyerParty);
        self::assertCount(1, $cn->taxBreakdown);
    }

    private function createSampleCreditNote(): CreditNote
    {
        return CreditNote::create(
            creditNoteNumber: 'CN-2026-000099',
            originalInvoiceId: 'inv-xyz',
            customerId: 'cust-99',
            reason: 'Duplicate charge',
            lineItems: [InvoiceLineItem::create('Charge reversal', 1, Money::of(5000, Currency::USD))],
            tax: Money::of(1000, Currency::USD),
            currency: Currency::USD,
        );
    }

    private function createParty(string $name): InvoiceParty
    {
        return new InvoiceParty(
            name: $name,
            vatNumber: 'BE0123456789',
            registrationNumber: null,
            legalForm: null,
            addressLine1: 'Main Street 1',
            addressLine2: null,
            city: 'Brussels',
            postalCode: '1000',
            country: 'BE',
            iban: null,
            bic: null,
            email: 'info@example.com',
            phone: null,
            gln: null,
        );
    }
}
