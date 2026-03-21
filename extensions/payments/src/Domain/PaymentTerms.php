<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Payment terms for an invoice.
 *
 * Encodes net payment days, early payment discount incentives,
 * and late payment interest penalties. Supports Peppol UNTDID 4461
 * payment means codes for e-invoicing interoperability.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PaymentTerms
{
    /**
     * @param int $netDays Number of days until payment is due
     * @param int|null $earlyDiscountPercent Early payment discount in basis points (e.g., 200 = 2%)
     * @param int|null $earlyDiscountDays Number of days within which early discount applies
     * @param int|null $lateInterestPercent Late payment interest rate in basis points per annum (e.g., 800 = 8%)
     * @param string|null $paymentMeansCode UNTDID 4461 payment means code (e.g., "30" = credit transfer, "58" = SEPA)
     * @param string|null $note Freeform payment terms description
     */
    public function __construct(
        public int $netDays,
        public ?int $earlyDiscountPercent = null,
        public ?int $earlyDiscountDays = null,
        public ?int $lateInterestPercent = null,
        public ?string $paymentMeansCode = null,
        public ?string $note = null,
    ) {}

    /**
     * Calculate the early discount amount for a given total.
     *
     * Returns zero if no early discount is configured.
     */
    #[NoDiscard]
    public function calculateEarlyDiscount(Money $total): Money
    {
        if ($this->earlyDiscountPercent === null || $this->earlyDiscountPercent === 0) {
            return Money::zero($total->currency);
        }

        return $total->percentage($this->earlyDiscountPercent);
    }

    /**
     * Calculate the late interest amount for a given total and number of overdue days.
     *
     * Interest is computed pro-rata: (total * rateBp / 10000) * (overdueDays / 365).
     * Returns zero if no late interest is configured or days <= 0.
     */
    #[NoDiscard]
    public function calculateLateInterest(Money $total, int $overdueDays): Money
    {
        if ($this->lateInterestPercent === null || $this->lateInterestPercent === 0 || $overdueDays <= 0) {
            return Money::zero($total->currency);
        }

        $annualInterest = $total->percentage($this->lateInterestPercent);
        $dailyAmount = (int) round($annualInterest->amount * $overdueDays / 365);

        return Money::of($dailyAmount, $total->currency);
    }

    /**
     * Whether early payment discount is configured.
     */
    public function hasEarlyDiscount(): bool
    {
        return $this->earlyDiscountPercent !== null
            && $this->earlyDiscountPercent > 0
            && $this->earlyDiscountDays !== null
            && $this->earlyDiscountDays > 0;
    }

    /**
     * Whether late payment interest is configured.
     */
    public function hasLateInterest(): bool
    {
        return $this->lateInterestPercent !== null && $this->lateInterestPercent > 0;
    }
}
