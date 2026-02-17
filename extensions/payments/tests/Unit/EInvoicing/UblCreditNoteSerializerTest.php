<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\EInvoicing;

use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\CreditNote;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\InvoiceParty;
use Pulsar\Extension\Payments\Domain\InvoiceTaxBreakdown;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\EInvoicing\UblCreditNoteSerializer;

final class UblCreditNoteSerializerTest extends TestCase
{
    private UblCreditNoteSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new UblCreditNoteSerializer();
    }

    #[Test]
    public function rendersValidXml(): void
    {
        $cn = $this->createCompleteCreditNote();
        $xml = $this->serializer->render($cn);

        $doc = new DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'Output must be valid XML');
    }

    #[Test]
    public function usesCorrectCreditNoteNamespace(): void
    {
        $cn = $this->createCompleteCreditNote();
        $xml = $this->serializer->render($cn);

        self::assertStringContainsString(
            'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
            $xml,
        );
    }

    #[Test]
    public function containsCreditNoteTypeCode381(): void
    {
        $cn = $this->createCompleteCreditNote();
        $xml = $this->serializer->render($cn);

        self::assertStringContainsString('>381<', $xml);
    }

    #[Test]
    public function containsCreditNoteNumber(): void
    {
        $cn = $this->createCompleteCreditNote();
        $xml = $this->serializer->render($cn);

        self::assertStringContainsString('CN-2026-000001', $xml);
    }

    #[Test]
    public function containsBillingReferenceToOriginalInvoice(): void
    {
        $cn = $this->createCompleteCreditNote();
        $xml = $this->serializer->render($cn);

        self::assertStringContainsString('BillingReference', $xml);
        self::assertStringContainsString('InvoiceDocumentReference', $xml);
        self::assertStringContainsString($cn->originalInvoiceId, $xml);
    }

    #[Test]
    public function containsCurrencyCode(): void
    {
        $cn = $this->createCompleteCreditNote();
        $xml = $this->serializer->render($cn);

        self::assertStringContainsString('EUR', $xml);
    }

    #[Test]
    public function containsSellerAndBuyerParties(): void
    {
        $cn = $this->createCompleteCreditNote();
        $xml = $this->serializer->render($cn);

        self::assertStringContainsString('AccountingSupplierParty', $xml);
        self::assertStringContainsString('AccountingCustomerParty', $xml);
        self::assertStringContainsString('Refund Corp', $xml);
        self::assertStringContainsString('Customer Ltd', $xml);
    }

    #[Test]
    public function containsTaxTotalAndSubtotals(): void
    {
        $cn = $this->createCompleteCreditNote();
        $xml = $this->serializer->render($cn);

        self::assertStringContainsString('TaxTotal', $xml);
        self::assertStringContainsString('TaxSubtotal', $xml);
    }

    #[Test]
    public function containsLegalMonetaryTotals(): void
    {
        $cn = $this->createCompleteCreditNote();
        $xml = $this->serializer->render($cn);

        self::assertStringContainsString('LegalMonetaryTotal', $xml);
        self::assertStringContainsString('LineExtensionAmount', $xml);
        self::assertStringContainsString('PayableAmount', $xml);
    }

    #[Test]
    public function containsCreditNoteLines(): void
    {
        $cn = $this->createCompleteCreditNote();
        $xml = $this->serializer->render($cn);

        self::assertStringContainsString('CreditNoteLine', $xml);
        self::assertStringContainsString('CreditedQuantity', $xml);
        self::assertStringContainsString('Defective widget', $xml);
    }

    #[Test]
    public function contentTypeIsApplicationXml(): void
    {
        self::assertSame('application/xml', $this->serializer->contentType());
    }

    #[Test]
    public function containsCustomizationAndProfileId(): void
    {
        $cn = $this->createCompleteCreditNote();
        $xml = $this->serializer->render($cn);

        self::assertStringContainsString('urn:cen.eu:en16931:2017', $xml);
        self::assertStringContainsString('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0', $xml);
    }

    #[Test]
    public function lineItemsContainTaxCategoryWhenSet(): void
    {
        $cn = CreditNote::create(
            creditNoteNumber: 'CN-TAX',
            originalInvoiceId: 'inv-1',
            customerId: 'cust-1',
            reason: 'Tax category test',
            lineItems: [
                InvoiceLineItem::create('Item S', 1, Money::of(1000, Currency::EUR), 'S', 2100),
                InvoiceLineItem::create('Item Z', 1, Money::of(500, Currency::EUR), 'Z', 0),
            ],
            tax: Money::of(210, Currency::EUR),
            currency: Currency::EUR,
        );

        $xml = $this->serializer->render($cn);

        self::assertStringContainsString('ClassifiedTaxCategory', $xml);
    }

    private function createCompleteCreditNote(): CreditNote
    {
        $seller = new InvoiceParty(
            name: 'Refund Corp',
            vatNumber: 'BE0123456789',
            registrationNumber: null,
            legalForm: null,
            addressLine1: 'Main St 1',
            addressLine2: null,
            city: 'Brussels',
            postalCode: '1000',
            country: 'BE',
            iban: null,
            bic: null,
            email: 'refunds@corp.be',
            phone: null,
            gln: null,
        );

        $buyer = new InvoiceParty(
            name: 'Customer Ltd',
            vatNumber: 'DE123456789',
            registrationNumber: null,
            legalForm: null,
            addressLine1: 'Berlin St 2',
            addressLine2: null,
            city: 'Berlin',
            postalCode: '10117',
            country: 'DE',
            iban: null,
            bic: null,
            email: 'billing@customer.de',
            phone: null,
            gln: null,
        );

        return CreditNote::create(
            creditNoteNumber: 'CN-2026-000001',
            originalInvoiceId: 'inv-original-001',
            customerId: 'cust-42',
            reason: 'Defective goods returned',
            lineItems: [
                InvoiceLineItem::create('Defective widget', 2, Money::of(5000, Currency::EUR), 'S', 2100),
            ],
            tax: Money::of(2100, Currency::EUR),
            currency: Currency::EUR,
            sellerParty: $seller,
            buyerParty: $buyer,
            taxBreakdown: [
                new InvoiceTaxBreakdown('S', 2100, Money::of(10000, Currency::EUR), Money::of(2100, Currency::EUR), 'BE'),
            ],
        );
    }
}
