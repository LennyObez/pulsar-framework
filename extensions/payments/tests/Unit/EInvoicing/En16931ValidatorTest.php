<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\EInvoicing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\InvoiceParty;
use Pulsar\Extension\Payments\Domain\InvoiceTaxBreakdown;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentTerms;
use Pulsar\Extension\Payments\EInvoicing\En16931Validator;

use function count;

final class En16931ValidatorTest extends TestCase
{
    private En16931Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new En16931Validator();
    }

    #[Test]
    public function fullyCompliantInvoicePasses(): void
    {
        $invoice = $this->createCompliantInvoice();
        $result = $this->validator->validate($invoice);

        self::assertTrue($result->valid);
        self::assertSame([], $result->violations);
    }

    #[Test]
    public function missingInvoiceNumberFails(): void
    {
        $invoice = Invoice::create(
            invoiceNumber: '',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(210, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $this->createSeller(),
            buyerParty: $this->createBuyer(),
            taxBreakdown: [$this->createTaxBreakdown()],
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertViolationContainsRule($result->violations, 'BR-01');
    }

    #[Test]
    public function missingSellerPartyFails(): void
    {
        $invoice = Invoice::create(
            invoiceNumber: 'INV-001',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            buyerParty: $this->createBuyer(),
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertViolationContainsRule($result->violations, 'BR-04');
    }

    #[Test]
    public function emptySellerNameFails(): void
    {
        $seller = new InvoiceParty(
            name: '',
            vatNumber: 'BE0123456789',
            registrationNumber: null,
            legalForm: null,
            addressLine1: 'Street 1',
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

        $invoice = Invoice::create(
            invoiceNumber: 'INV-002',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $seller,
            buyerParty: $this->createBuyer(),
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertViolationContainsRule($result->violations, 'BR-04');
    }

    #[Test]
    public function missingSellerAddressFieldsFails(): void
    {
        $seller = new InvoiceParty(
            name: 'Seller Corp',
            vatNumber: 'BE0123456789',
            registrationNumber: null,
            legalForm: null,
            addressLine1: '',
            addressLine2: null,
            city: '',
            postalCode: '',
            country: '',
            iban: null,
            bic: null,
            email: 'info@example.com',
            phone: null,
            gln: null,
        );

        $invoice = Invoice::create(
            invoiceNumber: 'INV-003',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $seller,
            buyerParty: $this->createBuyer(),
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertViolationContainsRule($result->violations, 'BR-05');
    }

    #[Test]
    public function missingSellerVatNumberFails(): void
    {
        $seller = new InvoiceParty(
            name: 'Seller Corp',
            vatNumber: null,
            registrationNumber: null,
            legalForm: null,
            addressLine1: 'Street 1',
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

        $invoice = Invoice::create(
            invoiceNumber: 'INV-004',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $seller,
            buyerParty: $this->createBuyer(),
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertViolationContainsRule($result->violations, 'BR-06');
    }

    #[Test]
    public function missingBuyerPartyFails(): void
    {
        $invoice = Invoice::create(
            invoiceNumber: 'INV-005',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $this->createSeller(),
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertViolationContainsRule($result->violations, 'BR-07');
    }

    #[Test]
    public function emptyBuyerNameFails(): void
    {
        $buyer = new InvoiceParty(
            name: '',
            vatNumber: null,
            registrationNumber: null,
            legalForm: null,
            addressLine1: 'Street 1',
            addressLine2: null,
            city: 'Berlin',
            postalCode: '10115',
            country: 'DE',
            iban: null,
            bic: null,
            email: 'buyer@example.com',
            phone: null,
            gln: null,
        );

        $invoice = Invoice::create(
            invoiceNumber: 'INV-006',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $this->createSeller(),
            buyerParty: $buyer,
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertViolationContainsRule($result->violations, 'BR-07');
    }

    #[Test]
    public function emptyBuyerCountryFails(): void
    {
        $buyer = new InvoiceParty(
            name: 'Buyer Ltd',
            vatNumber: null,
            registrationNumber: null,
            legalForm: null,
            addressLine1: 'Street 1',
            addressLine2: null,
            city: 'Berlin',
            postalCode: '10115',
            country: '',
            iban: null,
            bic: null,
            email: 'buyer@example.com',
            phone: null,
            gln: null,
        );

        $invoice = Invoice::create(
            invoiceNumber: 'INV-007',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $this->createSeller(),
            buyerParty: $buyer,
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertViolationContainsRule($result->violations, 'BR-08');
    }

    #[Test]
    public function emptyLineItemsFails(): void
    {
        $invoice = Invoice::create(
            invoiceNumber: 'INV-008',
            customerId: 'cust-1',
            lineItems: [],
            tax: Money::of(0, Currency::USD),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $this->createSeller(),
            buyerParty: $this->createBuyer(),
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertViolationContainsRule($result->violations, 'BR-09');
    }

    #[Test]
    public function missingDueDateAndPaymentTermsFails(): void
    {
        $invoice = Invoice::create(
            invoiceNumber: 'INV-009',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            sellerParty: $this->createSeller(),
            buyerParty: $this->createBuyer(),
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertViolationContainsRule($result->violations, 'BR-10');
    }

    #[Test]
    public function paymentTermsWithoutDueDatePasses(): void
    {
        $invoice = Invoice::create(
            invoiceNumber: 'INV-010',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            sellerParty: $this->createSeller(),
            buyerParty: $this->createBuyer(),
            paymentTerms: new PaymentTerms(netDays: 30),
        );

        $result = $this->validator->validate($invoice);

        $dueDateViolations = array_filter(
            $result->violations,
            static fn($v) => $v->rule === 'BR-10',
        );

        self::assertCount(0, $dueDateViolations);
    }

    #[Test]
    public function nonZeroTaxWithoutBreakdownFails(): void
    {
        $invoice = Invoice::create(
            invoiceNumber: 'INV-011',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(210, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $this->createSeller(),
            buyerParty: $this->createBuyer(),
            taxBreakdown: [],
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertViolationContainsRule($result->violations, 'BR-CO-15');
    }

    #[Test]
    public function zeroTaxWithoutBreakdownPasses(): void
    {
        $invoice = Invoice::create(
            invoiceNumber: 'INV-012',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $this->createSeller(),
            buyerParty: $this->createBuyer(),
        );

        $result = $this->validator->validate($invoice);

        $taxViolations = array_filter(
            $result->violations,
            static fn($v) => $v->rule === 'BR-CO-15',
        );

        self::assertCount(0, $taxViolations);
    }

    #[Test]
    public function multipleViolationsReportedTogether(): void
    {
        $invoice = Invoice::create(
            invoiceNumber: '',
            customerId: 'cust-1',
            lineItems: [],
            tax: Money::of(100, Currency::USD),
        );

        $result = $this->validator->validate($invoice);

        self::assertFalse($result->valid);
        self::assertGreaterThanOrEqual(4, count($result->violations));
    }

    private function createCompliantInvoice(): Invoice
    {
        return Invoice::create(
            invoiceNumber: 'INV-2026-000001',
            customerId: 'cust-1',
            lineItems: [
                InvoiceLineItem::create('Consulting service', 10, Money::of(15000, Currency::EUR), 'S', 2100),
            ],
            tax: Money::of(31500, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $this->createSeller(),
            buyerParty: $this->createBuyer(),
            paymentTerms: new PaymentTerms(netDays: 30, paymentMeansCode: '30'),
            taxBreakdown: [$this->createTaxBreakdown()],
        );
    }

    private function createSeller(): InvoiceParty
    {
        return new InvoiceParty(
            name: 'Acme Corp BV',
            vatNumber: 'BE0123456789',
            registrationNumber: '0123.456.789',
            legalForm: 'BV',
            addressLine1: 'Rue de la Loi 1',
            addressLine2: null,
            city: 'Brussels',
            postalCode: '1000',
            country: 'BE',
            iban: 'BE68539007547034',
            bic: 'BBRUBEBB',
            email: 'billing@acme.be',
            phone: '+32 2 000 0000',
            gln: '5412345000013',
        );
    }

    private function createBuyer(): InvoiceParty
    {
        return new InvoiceParty(
            name: 'TechStart GmbH',
            vatNumber: 'DE123456789',
            registrationNumber: 'HRB 12345',
            legalForm: 'GmbH',
            addressLine1: 'Friedrichstraße 100',
            addressLine2: null,
            city: 'Berlin',
            postalCode: '10117',
            country: 'DE',
            iban: null,
            bic: null,
            email: 'invoices@techstart.de',
            phone: null,
            gln: null,
        );
    }

    private function createTaxBreakdown(): InvoiceTaxBreakdown
    {
        return new InvoiceTaxBreakdown(
            taxCategoryCode: 'S',
            taxRatePercent: 2100,
            taxableAmount: Money::of(150000, Currency::EUR),
            taxAmount: Money::of(31500, Currency::EUR),
            jurisdiction: 'BE',
        );
    }

    /**
     * @param list<\Pulsar\Extension\Payments\EInvoicing\ValidationViolation> $violations
     */
    private static function assertViolationContainsRule(array $violations, string $rule): void
    {
        $found = false;

        foreach ($violations as $violation) {
            if ($violation->rule === $rule) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, "Expected violation with rule '{$rule}' not found");
    }
}
