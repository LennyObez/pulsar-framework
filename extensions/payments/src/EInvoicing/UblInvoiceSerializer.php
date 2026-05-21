<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\InvoiceRendererInterface;
use Pulsar\Extension\Payments\Domain\Invoice;
use Pulsar\Extension\Payments\Domain\InvoiceParty;
use Pulsar\Extension\Payments\Domain\InvoiceTaxBreakdown;
use XMLWriter;

use function sprintf;

/**
 * UBL 2.1 Invoice XML serializer conforming to EN 16931.
 *
 * Generates syntactically valid UBL 2.1 Invoice documents following
 * the European Standard EN 16931-1:2017 and its CIUS (Core Invoice
 * Usage Specifications). The output is compatible with Peppol BIS
 * Billing 3.0 and national CIUS variants (CIUS-AT, CIUS-IT, CIUS-FR).
 *
 * XML namespaces:
 * - Invoice: urn:oasis:names:specification:ubl:schema:xsd:Invoice-2
 * - CAC: urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2
 * - CBC: urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Use InvoiceRendererInterface')]
final readonly class UblInvoiceSerializer implements InvoiceRendererInterface
{
    private const string NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const string NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const string NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    #[Override]
    public function render(Invoice $invoice): string
    {
        $w = new XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');

        $w->startElementNs(null, 'Invoice', self::NS_INVOICE);
        $w->writeAttributeNs('xmlns', 'cac', null, self::NS_CAC);
        $w->writeAttributeNs('xmlns', 'cbc', null, self::NS_CBC);

        // Customization ID for Peppol BIS Billing 3.0
        $w->startElementNs('cbc', 'CustomizationID', null);
        $w->text('urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0');
        $w->endElement();

        // Profile ID
        $w->startElementNs('cbc', 'ProfileID', null);
        $w->text('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $w->endElement();

        // Invoice number (BT-1)
        $w->startElementNs('cbc', 'ID', null);
        $w->text($invoice->invoiceNumber);
        $w->endElement();

        // Issue date (BT-2)
        $w->startElementNs('cbc', 'IssueDate', null);
        $w->text($invoice->createdAt->format('Y-m-d'));
        $w->endElement();

        // Due date (BT-9)
        if ($invoice->dueDate !== null) {
            $w->startElementNs('cbc', 'DueDate', null);
            $w->text($invoice->dueDate->format('Y-m-d'));
            $w->endElement();
        }

        // Invoice type code (BT-3): 380 = Commercial invoice
        $w->startElementNs('cbc', 'InvoiceTypeCode', null);
        $w->text('380');
        $w->endElement();

        // Currency (BT-5)
        $w->startElementNs('cbc', 'DocumentCurrencyCode', null);
        $w->text($invoice->subtotal->currency->value);
        $w->endElement();

        // Seller (BG-4)
        if ($invoice->sellerParty !== null) {
            $this->writeParty($w, $invoice->sellerParty, 'AccountingSupplierParty');
        }

        // Buyer (BG-7)
        if ($invoice->buyerParty !== null) {
            $this->writeParty($w, $invoice->buyerParty, 'AccountingCustomerParty');
        }

        // Payment terms (BG-16)
        if ($invoice->paymentTerms !== null) {
            $w->startElementNs('cac', 'PaymentTerms', null);

            $noteText = $invoice->paymentTerms->note
                ?? sprintf('Net %d days', $invoice->paymentTerms->netDays);

            $w->startElementNs('cbc', 'Note', null);
            $w->text($noteText);
            $w->endElement();

            if ($invoice->paymentTerms->paymentMeansCode !== null) {
                $w->startElementNs('cbc', 'PaymentMeansCode', null);
                $w->text($invoice->paymentTerms->paymentMeansCode);
                $w->endElement();
            }

            $w->endElement();
        }

        // Payment means: bank transfer with IBAN/BIC from seller
        if ($invoice->sellerParty !== null && $invoice->sellerParty->iban !== null) {
            $w->startElementNs('cac', 'PaymentMeans', null);

            $meansCode = $invoice->paymentTerms !== null
                ? ($invoice->paymentTerms->paymentMeansCode ?? '30')
                : '30';
            $w->startElementNs('cbc', 'PaymentMeansCode', null);
            $w->text($meansCode);
            $w->endElement();

            $w->startElementNs('cac', 'PayeeFinancialAccount', null);
            $w->startElementNs('cbc', 'ID', null);
            $w->text($invoice->sellerParty->iban);
            $w->endElement();

            if ($invoice->sellerParty->bic !== null) {
                $w->startElementNs('cac', 'FinancialInstitutionBranch', null);
                $w->startElementNs('cbc', 'ID', null);
                $w->text($invoice->sellerParty->bic);
                $w->endElement();
                $w->endElement();
            }

            $w->endElement(); // PayeeFinancialAccount
            $w->endElement(); // PaymentMeans
        }

        // Tax total (BG-22)
        $w->startElementNs('cac', 'TaxTotal', null);
        $w->startElementNs('cbc', 'TaxAmount', null);
        $w->writeAttribute('currencyID', $invoice->tax->currency->value);
        $w->text($invoice->tax->format());
        $w->endElement();

        // Tax subtotals (BG-23)
        foreach ($invoice->taxBreakdown as $breakdown) {
            $this->writeTaxSubtotal($w, $breakdown);
        }

        $w->endElement(); // TaxTotal

        // Legal monetary totals (BG-21)
        $w->startElementNs('cac', 'LegalMonetaryTotal', null);

        $w->startElementNs('cbc', 'LineExtensionAmount', null);
        $w->writeAttribute('currencyID', $invoice->subtotal->currency->value);
        $w->text($invoice->subtotal->format());
        $w->endElement();

        $w->startElementNs('cbc', 'TaxExclusiveAmount', null);
        $w->writeAttribute('currencyID', $invoice->subtotal->currency->value);
        $w->text($invoice->subtotal->format());
        $w->endElement();

        $w->startElementNs('cbc', 'TaxInclusiveAmount', null);
        $w->writeAttribute('currencyID', $invoice->total->currency->value);
        $w->text($invoice->total->format());
        $w->endElement();

        $w->startElementNs('cbc', 'PayableAmount', null);
        $w->writeAttribute('currencyID', $invoice->total->currency->value);
        $w->text($invoice->total->format());
        $w->endElement();

        $w->endElement(); // LegalMonetaryTotal

        // Invoice lines (BG-25)
        foreach ($invoice->lineItems as $index => $item) {
            $w->startElementNs('cac', 'InvoiceLine', null);

            $w->startElementNs('cbc', 'ID', null);
            $w->text((string) ($index + 1));
            $w->endElement();

            $w->startElementNs('cbc', 'InvoicedQuantity', null);
            $w->writeAttribute('unitCode', 'C62'); // "one" (unit)
            $w->text((string) $item->quantity);
            $w->endElement();

            $w->startElementNs('cbc', 'LineExtensionAmount', null);
            $w->writeAttribute('currencyID', $item->total->currency->value);
            $w->text($item->total->format());
            $w->endElement();

            // Item
            $w->startElementNs('cac', 'Item', null);

            $w->startElementNs('cbc', 'Name', null);
            $w->text($item->description);
            $w->endElement();

            // Classified tax category
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

            // Price
            $w->startElementNs('cac', 'Price', null);
            $w->startElementNs('cbc', 'PriceAmount', null);
            $w->writeAttribute('currencyID', $item->unitPrice->currency->value);
            $w->text($item->unitPrice->format());
            $w->endElement();
            $w->endElement(); // Price

            $w->endElement(); // InvoiceLine
        }

        $w->endElement(); // Invoice
        $w->endDocument();

        return $w->outputMemory();
    }

    #[Override]
    public function contentType(): string
    {
        return 'application/xml';
    }

    private function writeParty(XMLWriter $w, InvoiceParty $party, string $elementName): void
    {
        $w->startElementNs('cac', $elementName, null);
        $w->startElementNs('cac', 'Party', null);

        // Party name
        $w->startElementNs('cac', 'PartyName', null);
        $w->startElementNs('cbc', 'Name', null);
        $w->text($party->name);
        $w->endElement();
        $w->endElement();

        // Postal address
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

        // VAT number (party tax scheme)
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

        // Legal entity
        $w->startElementNs('cac', 'PartyLegalEntity', null);
        $w->startElementNs('cbc', 'RegistrationName', null);
        $w->text($party->name);
        $w->endElement();

        if ($party->registrationNumber !== null) {
            $w->startElementNs('cbc', 'CompanyID', null);
            $w->text($party->registrationNumber);
            $w->endElement();
        }

        if ($party->legalForm !== null) {
            $w->startElementNs('cbc', 'CompanyLegalForm', null);
            $w->text($party->legalForm);
            $w->endElement();
        }

        $w->endElement(); // PartyLegalEntity

        // Contact
        $w->startElementNs('cac', 'Contact', null);
        $w->startElementNs('cbc', 'ElectronicMail', null);
        $w->text($party->email);
        $w->endElement();

        if ($party->phone !== null) {
            $w->startElementNs('cbc', 'Telephone', null);
            $w->text($party->phone);
            $w->endElement();
        }

        $w->endElement(); // Contact

        // Endpoint ID for Peppol (GLN)
        if ($party->gln !== null) {
            $w->startElementNs('cbc', 'EndpointID', null);
            $w->writeAttribute('schemeID', '0088');
            $w->text($party->gln);
            $w->endElement();
        }

        $w->endElement(); // Party
        $w->endElement(); // AccountingSupplierParty / AccountingCustomerParty
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
