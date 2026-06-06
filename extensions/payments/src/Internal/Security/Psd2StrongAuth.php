<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Security;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentMethod;

/**
 * PSD2 Strong Customer Authentication (SCA) compliance.
 *
 * Determines whether a payment requires SCA under the EU Payment
 * Services Directive 2 (PSD2). SCA requires two or more independent
 * authentication elements from:
 * - Knowledge (something the user knows)
 * - Possession (something the user has)
 * - Inherence (something the user is)
 *
 * Exemptions may apply for:
 * - Low-value transactions (under EUR 30)
 * - Merchant-initiated transactions (MIT)
 * - Trusted beneficiaries
 * - Recurring transactions (after initial authentication)
 */
#[Internal]
final readonly class Psd2StrongAuth
{
    /**
     * Low-value transaction threshold in EUR minor units (30.00 EUR = 3000 cents).
     */
    private const int LOW_VALUE_THRESHOLD_EUR = 3000;

    /**
     * Determine if SCA is required for a transaction.
     *
     * @param bool $isRecurring Whether this is a subsequent recurring charge
     * @param bool $isTrustedBeneficiary Whether the merchant is a trusted beneficiary
     */
    #[NoDiscard]
    public static function requiresSca(
        Money $amount,
        PaymentMethod $method,
        bool $isRecurring = false,
        bool $isTrustedBeneficiary = false,
    ): bool {
        // Method must support SCA
        if (!$method->requiresSca()) {
            return false;
        }

        // Trusted beneficiary exemption
        if ($isTrustedBeneficiary) {
            return false;
        }

        // Recurring transaction exemption (after initial SCA)
        if ($isRecurring) {
            return false;
        }

        // Low-value exemption (EUR only)
        if ($amount->currency->value === 'EUR' && $amount->amount < self::LOW_VALUE_THRESHOLD_EUR) {
            return false;
        }

        return true;
    }

    /**
     * Get the SCA exemption reason if applicable.
     *
     * @return string|null Exemption reason or null if SCA is required
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public static function exemptionReason(
        Money $amount,
        PaymentMethod $method,
        bool $isRecurring = false,
        bool $isTrustedBeneficiary = false,
    ): ?string {
        if (!$method->requiresSca()) {
            return 'method_not_applicable';
        }

        if ($isTrustedBeneficiary) {
            return 'trusted_beneficiary';
        }

        if ($isRecurring) {
            return 'recurring_mit';
        }

        if ($amount->currency->value === 'EUR' && $amount->amount < self::LOW_VALUE_THRESHOLD_EUR) {
            return 'low_value';
        }

        return null;
    }
}
