<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Domain\CreditNote;
use Pulsar\Extension\Payments\Domain\InvoiceParty;
use Pulsar\Extension\Payments\Domain\InvoiceTaxBreakdown;
use XMLWriter;

use function sprintf;

/**
 * UBL 2.1 CreditNote XML serializer conforming to EN 16931.
 *
 * Generates syntactically valid UBL 2.1 CreditNote documents following
 * the European Standard EN 16931. The credit note references the
 * original invoice for full audit traceability.
 *
 * XML namespace: urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'E-invoicing serializer')]
final readonly class UblCreditNoteSerializer
{
    private const string NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    private const string NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const string NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * Render a CreditNote as UBL 2.1 XML.
     */
    public function render(CreditNote $creditNote): string
    {
        $w = new XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');

        $w->startElementNs(null, 'CreditNote', self::NS_CREDIT_NOTE);
        $w->writeAttributeNs('xmlns', 'cac', null, self::NS_CAC);
        $w->writeAttributeNs('xmlns', 'cbc', null, self::NS_CBC);

        // Customization ID
        $w->startElementNs('cbc', 'CustomizationID', null);
        $w->text('urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0');
        $w->endElement();

        // Profile ID
        $w->startElementNs('cbc', 'ProfileID', null);
        $w->text('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $w->endElement();

        // Credit note number
        $w->startElementNs('cbc', 'ID', null);
        $w->text($creditNote->creditNoteNumber);
        $w->endElement();

        // Issue date
        $w->startElementNs('cbc', 'IssueDate', null);
        $w->text($creditNote->createdAt->format('Y-m-d'));
        $w->endElement();

        // Credit note type code: 381 = Credit note
        $w->startElementNs('cbc', 'CreditNoteTypeCode', null);
        $w->text('381');
        $w->endElement();

        // Currency
        $w->startElementNs('cbc', 'DocumentCurrencyCode', null);
        $w->text($creditNote->currency->value);
        $w->endElement();

        // Billing reference (original invoice)
        $w->startElementNs('cac', 'BillingReference', null);
        $w->startElementNs('cac', 'InvoiceDocumentReference', null);
        $w->startElementNs('cbc', 'ID', null);
        $w->text($creditNote->originalInvoiceId);
        $w->endElement();
        $w->endElement();
        $w->endElement();

        // Seller
        if ($creditNote->sellerParty !== null) {
            $this->writeParty($w, $creditNote->sellerParty, 'AccountingSupplierParty');
        }

        // Buyer
        if ($creditNote->buyerParty !== null) {
            $this->writeParty($w, $creditNote->buyerParty, 'AccountingCustomerParty');
        }

        // Tax total
        $w->startElementNs('cac', 'TaxTotal', null);
        $w->startElementNs('cbc', 'TaxAmount', null);
        $w->writeAttribute('currencyID', $creditNote->tax->currency->value);
        $w->text($creditNote->tax->format());
        $w->endElement();

        foreach ($creditNote->taxBreakdown as $breakdown) {
            $this->writeTaxSubtotal($w, $breakdown);
        }

        $w->endElement(); // TaxTotal

        // Legal monetary totals
        $w->startElementNs('cac', 'LegalMonetaryTotal', null);

        $w->startElementNs('cbc', 'LineExtensionAmount', null);
        $w->writeAttribute('currencyID', $creditNote->subtotal->currency->value);
        $w->text($creditNote->subtotal->format());
        $w->endElement();

        $w->startElementNs('cbc', 'TaxExclusiveAmount', null);
        $w->writeAttribute('currencyID', $creditNote->subtotal->currency->value);
        $w->text($creditNote->subtotal->format());
        $w->endElement();

        $w->startElementNs('cbc', 'TaxInclusiveAmount', null);
        $w->writeAttribute('currencyID', $creditNote->total->currency->value);
        $w->text($creditNote->total->format());
        $w->endElement();

        $w->startElementNs('cbc', 'PayableAmount', null);
        $w->writeAttribute('currencyID', $creditNote->total->currency->value);
        $w->text($creditNote->total->format());
        $w->endElement();

        $w->endElement(); // LegalMonetaryTotal

        // Credit note lines
        foreach ($creditNote->lineItems as $index => $item) {
            $w->startElementNs('cac', 'CreditNoteLine', null);

            $w->startElementNs('cbc', 'ID', null);
            $w->text((string) ($index + 1));
            $w->endElement();

            $w->startElementNs('cbc', 'CreditedQuantity', null);
            $w->writeAttribute('unitCode', 'C62');
            $w->text((string) $item->quantity);
            $w->endElement();

            $w->startElementNs('cbc', 'LineExtensionAmount', null);
            $w->writeAttribute('currencyID', $item->total->currency->value);
            $w->text($item->total->format());
            $w->endElement();

            $w->startElementNs('cac', 'Item', null);
            $w->startElementNs('cbc', 'Name', null);
            $w->text($item->description);
            $w->endElement();

            if ($item->taxCategory !== null) {
                $w->startElementNs('cac', 'ClassifiedTaxCategory', null);
                $w->startElementNs('cbc', 'ID', null);
                $w->text($item->taxCategory);
                $w->endElement();

                if ($item->taxRatePercent !== null) {
                    $w->startElementNs('cbc', 'Percent', null);
                    $w->text(sprintf('%.2f', $item->taxRatePercent / 100));
                    $w->endElement();
                }

                $w->startElementNs('cac', 'TaxScheme', null);
                $w->startElementNs('cbc', 'ID', null);
                $w->text('VAT');
                $w->endElement();
                $w->endElement();
                $w->endElement(); // ClassifiedTaxCategory
            }

            $w->endElement(); // Item

            $w->startElementNs('cac', 'Price', null);
            $w->startElementNs('cbc', 'PriceAmount', null);
            $w->writeAttribute('currencyID', $item->unitPrice->currency->value);
            $w->text($item->unitPrice->format());
            $w->endElement();
            $w->endElement(); // Price

            $w->endElement(); // CreditNoteLine
        }

        $w->endElement(); // CreditNote
        $w->endDocument();

        return $w->outputMemory();
    }

    public function contentType(): string
    {
        return 'application/xml';
    }

    private function writeParty(XMLWriter $w, InvoiceParty $party, string $elementName): void
    {
        $w->startElementNs('cac', $elementName, null);
        $w->startElementNs('cac', 'Party', null);

        $w->startElementNs('cac', 'PartyName', null);
        $w->startElementNs('cbc', 'Name', null);
        $w->text($party->name);
        $w->endElement();
        $w->endElement();

        $w->startElementNs('cac', 'PostalAddress', null);
        $w->startElementNs('cbc', 'StreetName', null);
        $w->text($party->addressLine1);
        $w->endElement();

        if ($party->addressLine2 !== null) {
            $w->startElementNs('cbc', 'AdditionalStreetName', null);
            $w->text($party->addressLine2);
            $w->endElement();
        }

        $w->startElementNs('cbc', 'CityName', null);
        $w->text($party->city);
        $w->endElement();

        $w->startElementNs('cbc', 'PostalZone', null);
        $w->text($party->postalCode);
        $w->endElement();

        $w->startElementNs('cac', 'Country', null);
        $w->startElementNs('cbc', 'IdentificationCode', null);
        $w->text($party->country);
        $w->endElement();
        $w->endElement();
        $w->endElement(); // PostalAddress

        if ($party->vatNumber !== null) {
            $w->startElementNs('cac', 'PartyTaxScheme', null);
            $w->startElementNs('cbc', 'CompanyID', null);
            $w->text($party->vatNumber);
            $w->endElement();
            $w->startElementNs('cac', 'TaxScheme', null);
            $w->startElementNs('cbc', 'ID', null);
            $w->text('VAT');
            $w->endElement();
            $w->endElement();
            $w->endElement();
        }

        $w->startElementNs('cac', 'PartyLegalEntity', null);
        $w->startElementNs('cbc', 'RegistrationName', null);
        $w->text($party->name);
        $w->endElement();
        $w->endElement();

        $w->startElementNs('cac', 'Contact', null);
        $w->startElementNs('cbc', 'ElectronicMail', null);
        $w->text($party->email);
        $w->endElement();
        $w->endElement();

        $w->endElement(); // Party
        $w->endElement();
    }

    private function writeTaxSubtotal(XMLWriter $w, InvoiceTaxBreakdown $breakdown): void
    {
        $w->startElementNs('cac', 'TaxSubtotal', null);

        $w->startElementNs('cbc', 'TaxableAmount', null);
        $w->writeAttribute('currencyID', $breakdown->taxableAmount->currency->value);
        $w->text($breakdown->taxableAmount->format());
        $w->endElement();

        $w->startElementNs('cbc', 'TaxAmount', null);
        $w->writeAttribute('currencyID', $breakdown->taxAmount->currency->value);
        $w->text($breakdown->taxAmount->format());
        $w->endElement();

        $w->startElementNs('cac', 'TaxCategory', null);
        $w->startElementNs('cbc', 'ID', null);
        $w->text($breakdown->taxCategoryCode);
        $w->endElement();

        $w->startElementNs('cbc', 'Percent', null);
        $w->text(sprintf('%.2f', $breakdown->taxRatePercent / 100));
        $w->endElement();

        $w->startElementNs('cac', 'TaxScheme', null);
        $w->startElementNs('cbc', 'ID', null);
        $w->text('VAT');
        $w->endElement();
        $w->endElement();
        $w->endElement(); // TaxCategory

        $w->endElement(); // TaxSubtotal
    }
}
