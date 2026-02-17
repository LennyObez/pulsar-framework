<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Invoice;

/**
 * Validates an Invoice against EN 16931 mandatory field requirements.
 *
 * Checks the presence and correctness of all fields required by the
 * European e-invoicing standard EN 16931-1:2017. This validator covers
 * the semantic model requirements; XML schema validation is handled
 * separately by the UBL serializers.
 *
 * Reference rules:
 * - BR-01: Invoice shall have an invoice number
 * - BR-02: Invoice shall have an issue date
 * - BR-03: Invoice shall have a currency code
 * - BR-04: Seller name is mandatory
 * - BR-05: Seller address with country code
 * - BR-06: Seller VAT identifier (if applicable)
 * - BR-07: Buyer name is mandatory
 * - BR-08: Buyer address with country code
 * - BR-09: At least one invoice line
 * - BR-10: Payment terms or due date
 * - BR-CO-15: Tax breakdown present when tax > 0
 */
#[Api(since: '1.0.0')]
final readonly class En16931Validator
{
    /**
     * Validate an invoice against EN 16931 mandatory requirements.
     */
    #[NoDiscard]
    public function validate(Invoice $invoice): ValidationResult
    {
        $violations = [];

        // BR-01: Invoice number
        if ($invoice->invoiceNumber === '') {
            $violations[] = new ValidationViolation(
                field: 'invoiceNumber',
                message: 'Invoice number is required (BR-01)',
                rule: 'BR-01',
            );
        }

        // BR-02: Issue date (createdAt serves as issue date)
        // Always present via constructor: no check needed

        // BR-03: Currency code: verify line items have consistent currency
        if ($invoice->lineItems !== []) {
            $expectedCurrency = $invoice->subtotal->currency;

            foreach ($invoice->lineItems as $index => $item) {
                if ($item->unitPrice->currency !== $expectedCurrency) {
                    $violations[] = new ValidationViolation(
                        field: "lineItems[{$index}].unitPrice.currency",
                        message: "Line item currency must match invoice currency {$expectedCurrency->value} (BR-03)",
                        rule: 'BR-03',
                    );
                }
            }
        }

        // BR-04: Seller name
        if ($invoice->sellerParty === null) {
            $violations[] = new ValidationViolation(
                field: 'sellerParty',
                message: 'Seller party is required (BR-04)',
                rule: 'BR-04',
            );
        } else {
            if ($invoice->sellerParty->name === '') {
                $violations[] = new ValidationViolation(
                    field: 'sellerParty.name',
                    message: 'Seller name is required (BR-04)',
                    rule: 'BR-04',
                );
            }

            // BR-05: Seller address country
            if ($invoice->sellerParty->country === '') {
                $violations[] = new ValidationViolation(
                    field: 'sellerParty.country',
                    message: 'Seller country is required (BR-05)',
                    rule: 'BR-05',
                );
            }

            if ($invoice->sellerParty->addressLine1 === '') {
                $violations[] = new ValidationViolation(
                    field: 'sellerParty.addressLine1',
                    message: 'Seller address is required (BR-05)',
                    rule: 'BR-05',
                );
            }

            if ($invoice->sellerParty->city === '') {
                $violations[] = new ValidationViolation(
                    field: 'sellerParty.city',
                    message: 'Seller city is required (BR-05)',
                    rule: 'BR-05',
                );
            }

            if ($invoice->sellerParty->postalCode === '') {
                $violations[] = new ValidationViolation(
                    field: 'sellerParty.postalCode',
                    message: 'Seller postal code is required (BR-05)',
                    rule: 'BR-05',
                );
            }

            // BR-06: Seller VAT identifier
            if ($invoice->sellerParty->vatNumber === null || $invoice->sellerParty->vatNumber === '') {
                $violations[] = new ValidationViolation(
                    field: 'sellerParty.vatNumber',
                    message: 'Seller VAT number is required (BR-06)',
                    rule: 'BR-06',
                );
            }
        }

        // BR-07: Buyer name
        if ($invoice->buyerParty === null) {
            $violations[] = new ValidationViolation(
                field: 'buyerParty',
                message: 'Buyer party is required (BR-07)',
                rule: 'BR-07',
            );
        } else {
            if ($invoice->buyerParty->name === '') {
                $violations[] = new ValidationViolation(
                    field: 'buyerParty.name',
                    message: 'Buyer name is required (BR-07)',
                    rule: 'BR-07',
                );
            }

            // BR-08: Buyer address country
            if ($invoice->buyerParty->country === '') {
                $violations[] = new ValidationViolation(
                    field: 'buyerParty.country',
                    message: 'Buyer country is required (BR-08)',
                    rule: 'BR-08',
                );
            }
        }

        // BR-09: At least one invoice line
        if ($invoice->lineItems === []) {
            $violations[] = new ValidationViolation(
                field: 'lineItems',
                message: 'At least one invoice line is required (BR-09)',
                rule: 'BR-09',
            );
        }

        // BR-10: Due date or payment terms
        if ($invoice->dueDate === null && $invoice->paymentTerms === null) {
            $violations[] = new ValidationViolation(
                field: 'dueDate',
                message: 'Due date or payment terms are required (BR-10)',
                rule: 'BR-10',
            );
        }

        // BR-CO-15: Tax breakdown when tax > 0
        if (!$invoice->tax->isZero() && $invoice->taxBreakdown === []) {
            $violations[] = new ValidationViolation(
                field: 'taxBreakdown',
                message: 'Tax breakdown is required when tax amount is non-zero (BR-CO-15)',
                rule: 'BR-CO-15',
            );
        }

        if ($violations === []) {
            return ValidationResult::pass();
        }

        return ValidationResult::fail($violations);
    }
}
