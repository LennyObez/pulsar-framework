<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\InvoiceParty;
use Pulsar\Extension\Payments\Domain\InvoiceStatus;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentTerms;
use Pulsar\Extension\Payments\Domain\Quote;
use Pulsar\Extension\Payments\Domain\QuoteStatus;

use function strlen;

final class QuoteTest extends TestCase
{
    #[Test]
    public function createBuildsDraftQuoteWithCorrectSubtotal(): void
    {
        $lineItems = [
            InvoiceLineItem::create('Consulting', 10, Money::of(15000, Currency::EUR)),
            InvoiceLineItem::create('Development', 5, Money::of(20000, Currency::EUR)),
        ];
        $tax = Money::of(55000, Currency::EUR);

        $quote = Quote::create(
            quoteNumber: 'QTE-2026-000001',
            customerId: 'cust-42',
            lineItems: $lineItems,
            tax: $tax,
            currency: Currency::EUR,
        );

        self::assertSame('QTE-2026-000001', $quote->quoteNumber);
        self::assertSame('cust-42', $quote->customerId);
        self::assertSame(QuoteStatus::Draft, $quote->status);
        self::assertSame(250000, $quote->subtotal->amount); // 10*15000 + 5*20000
        self::assertSame(55000, $quote->tax->amount);
        self::assertSame(305000, $quote->total->amount);
        self::assertSame(Currency::EUR, $quote->currency);
        self::assertCount(2, $quote->lineItems);
        self::assertSame(32, strlen($quote->id));
    }

    #[Test]
    public function createWithEmptyLineItemsUsesZeroSubtotal(): void
    {
        $quote = Quote::create(
            quoteNumber: 'QTE-2026-000002',
            customerId: 'cust-1',
            lineItems: [],
            tax: Money::of(0, Currency::USD),
            currency: Currency::USD,
        );

        self::assertSame(0, $quote->subtotal->amount);
        self::assertSame(Currency::USD, $quote->subtotal->currency);
        self::assertSame(0, $quote->total->amount);
    }

    #[Test]
    public function createWithAllOptionalParams(): void
    {
        $validUntil = new DateTimeImmutable('+30 days');
        $seller = $this->createParty('Seller Corp');
        $buyer = $this->createParty('Buyer Ltd');
        $terms = new PaymentTerms(netDays: 30);

        $quote = Quote::create(
            quoteNumber: 'QTE-2026-000003',
            customerId: 'cust-3',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::GBP))],
            tax: Money::of(200, Currency::GBP),
            currency: Currency::GBP,
            validUntil: $validUntil,
            notes: 'Special terms apply',
            sellerParty: $seller,
            buyerParty: $buyer,
            paymentTerms: $terms,
            metadata: ['campaign' => 'Q1'],
        );

        self::assertSame($validUntil, $quote->validUntil);
        self::assertSame('Special terms apply', $quote->notes);
        self::assertSame($seller, $quote->sellerParty);
        self::assertSame($buyer, $quote->buyerParty);
        self::assertSame($terms, $quote->paymentTerms);
        self::assertSame(['campaign' => 'Q1'], $quote->metadata);
    }

    #[Test]
    public function sendTransitionsToSentStatus(): void
    {
        $quote = $this->createSampleQuote();
        $sent = $quote->send();

        self::assertSame(QuoteStatus::Sent, $sent->status);
        self::assertSame(QuoteStatus::Draft, $quote->status, 'original is immutable');
    }

    #[Test]
    public function acceptTransitionsToAcceptedStatus(): void
    {
        $quote = $this->createSampleQuote();
        $accepted = $quote->accept();

        self::assertSame(QuoteStatus::Accepted, $accepted->status);
        self::assertSame(QuoteStatus::Draft, $quote->status);
    }

    #[Test]
    public function rejectTransitionsToRejectedStatus(): void
    {
        $quote = $this->createSampleQuote();
        $rejected = $quote->reject();

        self::assertSame(QuoteStatus::Rejected, $rejected->status);
    }

    #[Test]
    public function expireTransitionsToExpiredStatus(): void
    {
        $quote = $this->createSampleQuote();
        $expired = $quote->expire();

        self::assertSame(QuoteStatus::Expired, $expired->status);
    }

    #[Test]
    public function isExpiredReturnsTrueWhenPastValidUntil(): void
    {
        $quote = Quote::create(
            quoteNumber: 'QTE-2026-000010',
            customerId: 'cust-10',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(100, Currency::USD))],
            tax: Money::of(0, Currency::USD),
            currency: Currency::USD,
            validUntil: new DateTimeImmutable('-1 day'),
        );

        self::assertTrue($quote->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseWhenValidUntilInFuture(): void
    {
        $quote = Quote::create(
            quoteNumber: 'QTE-2026-000011',
            customerId: 'cust-11',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(100, Currency::USD))],
            tax: Money::of(0, Currency::USD),
            currency: Currency::USD,
            validUntil: new DateTimeImmutable('+30 days'),
        );

        self::assertFalse($quote->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseWhenNoValidUntil(): void
    {
        $quote = $this->createSampleQuote();

        self::assertFalse($quote->isExpired());
    }

    #[Test]
    public function toInvoiceCreatesInvoiceFromQuoteData(): void
    {
        $seller = $this->createParty('Seller Corp');
        $buyer = $this->createParty('Buyer Ltd');
        $terms = new PaymentTerms(netDays: 30);

        $lineItems = [
            InvoiceLineItem::create('Service A', 2, Money::of(5000, Currency::EUR)),
        ];

        $quote = Quote::create(
            quoteNumber: 'QTE-2026-000020',
            customerId: 'cust-20',
            lineItems: $lineItems,
            tax: Money::of(2100, Currency::EUR),
            currency: Currency::EUR,
            sellerParty: $seller,
            buyerParty: $buyer,
            paymentTerms: $terms,
        );

        $invoice = $quote->toInvoice('INV-2026-000001');

        self::assertSame('INV-2026-000001', $invoice->invoiceNumber);
        self::assertSame('cust-20', $invoice->customerId);
        self::assertSame(InvoiceStatus::Draft, $invoice->status);
        self::assertSame(10000, $invoice->subtotal->amount);
        self::assertSame(2100, $invoice->tax->amount);
        self::assertSame(12100, $invoice->total->amount);
        self::assertSame($seller, $invoice->sellerParty);
        self::assertSame($buyer, $invoice->buyerParty);
        self::assertSame($terms, $invoice->paymentTerms);
        self::assertCount(1, $invoice->lineItems);
        self::assertSame($quote->id, $invoice->metadata['quote_id']);
        self::assertSame('QTE-2026-000020', $invoice->metadata['quote_number']);
    }

    #[Test]
    public function toInvoiceUsesPaymentTermsForDueDateWhenNotSpecified(): void
    {
        $terms = new PaymentTerms(netDays: 45);
        $quote = Quote::create(
            quoteNumber: 'QTE-2026-000021',
            customerId: 'cust-21',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::USD))],
            tax: Money::of(0, Currency::USD),
            currency: Currency::USD,
            paymentTerms: $terms,
        );

        $invoice = $quote->toInvoice('INV-2026-000002');

        self::assertNotNull($invoice->dueDate);
    }

    #[Test]
    public function toInvoiceUsesExplicitDueDateWhenProvided(): void
    {
        $dueDate = new DateTimeImmutable('+60 days');
        $quote = $this->createSampleQuote();

        $invoice = $quote->toInvoice('INV-2026-000003', $dueDate);

        self::assertSame($dueDate, $invoice->dueDate);
    }

    #[Test]
    #[DataProvider('statusTransitionProvider')]
    public function statusTransitionsPreserveImmutability(string $method, QuoteStatus $expectedStatus): void
    {
        $quote = $this->createSampleQuote();
        /** @var Quote $transitioned */
        $transitioned = $quote->{$method}();

        self::assertSame($expectedStatus, $transitioned->status);
        self::assertSame(QuoteStatus::Draft, $quote->status);
        self::assertNotSame($quote, $transitioned);
    }

    /**
     * @return iterable<string, array{string, QuoteStatus}>
     */
    public static function statusTransitionProvider(): iterable
    {
        yield 'send' => ['send', QuoteStatus::Sent];
        yield 'accept' => ['accept', QuoteStatus::Accepted];
        yield 'reject' => ['reject', QuoteStatus::Rejected];
        yield 'expire' => ['expire', QuoteStatus::Expired];
    }

    private function createSampleQuote(): Quote
    {
        return Quote::create(
            quoteNumber: 'QTE-2026-000099',
            customerId: 'cust-99',
            lineItems: [InvoiceLineItem::create('Widget', 1, Money::of(5000, Currency::USD))],
            tax: Money::of(1000, Currency::USD),
            currency: Currency::USD,
        );
    }

    private function createParty(string $name): InvoiceParty
    {
        return new InvoiceParty(
            name: $name,
            vatNumber: 'BE0123456789',
            registrationNumber: '0123.456.789',
            legalForm: 'BV',
            addressLine1: 'Main Street 1',
            addressLine2: null,
            city: 'Brussels',
            postalCode: '1000',
            country: 'BE',
            iban: 'BE68539007547034',
            bic: 'BBRUBEBB',
            email: 'info@example.com',
            phone: '+32 2 000 0000',
            gln: '5412345000013',
        );
    }
}
