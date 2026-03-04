<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\EInvoicing;

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\InvoiceParty;
use Pulsar\Extension\Payments\Domain\InvoiceTaxBreakdown;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentTerms;
use Pulsar\Extension\Payments\EInvoicing\UblInvoiceSerializer;

final class UblInvoiceSerializerTest extends TestCase
{
    private UblInvoiceSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new UblInvoiceSerializer();
    }

    #[Test]
    public function rendersValidXml(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        $doc = new DOMDocument();
        $loaded = $doc->loadXML($xml);

        self::assertTrue($loaded, 'Output must be valid XML');
    }

    #[Test]
    public function usesCorrectInvoiceNamespace(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString(
            'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            $xml,
        );
    }

    #[Test]
    public function includesCacAndCbcNamespaces(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            $xml,
        );
        self::assertStringContainsString(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            $xml,
        );
    }

    #[Test]
    public function containsCustomizationAndProfileId(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('urn:cen.eu:en16931:2017', $xml);
        self::assertStringContainsString('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0', $xml);
    }

    #[Test]
    public function containsInvoiceNumber(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('INV-2026-000001', $xml);
    }

    #[Test]
    public function containsIssueDate(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString($invoice->createdAt->format('Y-m-d'), $xml);
    }

    #[Test]
    public function containsDueDate(): void
    {
        $dueDate = new DateTimeImmutable('+30 days');
        $invoice = Invoice::create(
            invoiceNumber: 'INV-DUE',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            dueDate: $dueDate,
            sellerParty: $this->createSeller(),
            buyerParty: $this->createBuyer(),
        );

        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString($dueDate->format('Y-m-d'), $xml);
    }

    #[Test]
    public function containsInvoiceTypeCode380(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('>380<', $xml);
    }

    #[Test]
    public function containsCurrencyCode(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('EUR', $xml);
    }

    #[Test]
    public function containsSellerPartyInformation(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('AccountingSupplierParty', $xml);
        self::assertStringContainsString('Acme Corp BV', $xml);
        self::assertStringContainsString('BE0123456789', $xml);
        self::assertStringContainsString('Brussels', $xml);
        self::assertStringContainsString('1000', $xml);
    }

    #[Test]
    public function containsBuyerPartyInformation(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('AccountingCustomerParty', $xml);
        self::assertStringContainsString('TechStart GmbH', $xml);
        self::assertStringContainsString('DE123456789', $xml);
        self::assertStringContainsString('Berlin', $xml);
    }

    #[Test]
    public function containsPaymentTerms(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('PaymentTerms', $xml);
        self::assertStringContainsString('Net 30 days', $xml);
    }

    #[Test]
    public function containsPaymentMeansWithIban(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('PaymentMeans', $xml);
        self::assertStringContainsString('PayeeFinancialAccount', $xml);
        self::assertStringContainsString('BE68539007547034', $xml);
        self::assertStringContainsString('BBRUBEBB', $xml);
    }

    #[Test]
    public function containsTaxTotal(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('TaxTotal', $xml);
        self::assertStringContainsString('TaxAmount', $xml);
    }

    #[Test]
    public function containsTaxSubtotals(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('TaxSubtotal', $xml);
        self::assertStringContainsString('TaxableAmount', $xml);
        self::assertStringContainsString('TaxCategory', $xml);
    }

    #[Test]
    public function containsLegalMonetaryTotals(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('LegalMonetaryTotal', $xml);
        self::assertStringContainsString('LineExtensionAmount', $xml);
        self::assertStringContainsString('TaxExclusiveAmount', $xml);
        self::assertStringContainsString('TaxInclusiveAmount', $xml);
        self::assertStringContainsString('PayableAmount', $xml);
    }

    #[Test]
    public function containsInvoiceLines(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('InvoiceLine', $xml);
        self::assertStringContainsString('InvoicedQuantity', $xml);
        self::assertStringContainsString('Consulting service', $xml);
    }

    #[Test]
    public function containsClassifiedTaxCategory(): void
    {
        $invoice = $this->createCompleteInvoice();
        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('ClassifiedTaxCategory', $xml);
        self::assertStringContainsString('TaxScheme', $xml);
    }

    #[Test]
    public function contentTypeIsApplicationXml(): void
    {
        self::assertSame('application/xml', $this->serializer->contentType());
    }

    #[Test]
    public function multipleLineItemsProduceMultipleInvoiceLines(): void
    {
        $invoice = Invoice::create(
            invoiceNumber: 'INV-MULTI',
            customerId: 'cust-1',
            lineItems: [
                InvoiceLineItem::create('Item A', 2, Money::of(1000, Currency::EUR), 'S', 2100),
                InvoiceLineItem::create('Item B', 3, Money::of(500, Currency::EUR), 'S', 2100),
                InvoiceLineItem::create('Item C', 1, Money::of(3000, Currency::EUR), 'Z', 0),
            ],
            tax: Money::of(735, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $this->createSeller(),
            buyerParty: $this->createBuyer(),
        );

        $xml = $this->serializer->render($invoice);

        self::assertStringContainsString('Item A', $xml);
        self::assertStringContainsString('Item B', $xml);
        self::assertStringContainsString('Item C', $xml);
    }

    #[Test]
    public function omitsOptionalPartyFieldsWhenNull(): void
    {
        $minimalParty = new InvoiceParty(
            name: 'Minimal',
            vatNumber: 'XX1234',
            registrationNumber: null,
            legalForm: null,
            addressLine1: 'Street',
            addressLine2: null,
            city: 'City',
            postalCode: '1000',
            country: 'XX',
            iban: null,
            bic: null,
            email: 'x@x.com',
            phone: null,
            gln: null,
        );

        $invoice = Invoice::create(
            invoiceNumber: 'INV-MINIMAL',
            customerId: 'cust-1',
            lineItems: [InvoiceLineItem::create('Item', 1, Money::of(1000, Currency::EUR))],
            tax: Money::of(0, Currency::EUR),
            dueDate: new DateTimeImmutable('+30 days'),
            sellerParty: $minimalParty,
            buyerParty: $minimalParty,
        );

        $xml = $this->serializer->render($invoice);
        $doc = new DOMDocument();
        self::assertTrue($doc->loadXML($xml));
        self::assertStringNotContainsString('AdditionalStreetName', $xml);
    }

    private function createCompleteInvoice(): Invoice
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
            taxBreakdown: [
                new InvoiceTaxBreakdown(
                    taxCategoryCode: 'S',
                    taxRatePercent: 2100,
                    taxableAmount: Money::of(150000, Currency::EUR),
                    taxAmount: Money::of(31500, Currency::EUR),
                    jurisdiction: 'BE',
                ),
            ],
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
}
