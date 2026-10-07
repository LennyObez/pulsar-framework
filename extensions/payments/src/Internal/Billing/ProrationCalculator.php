<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Billing;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\RoundingMode;
use Pulsar\Extension\Payments\Domain\Subscription;

/**
 * Calculates prorated amounts for mid-cycle plan changes.
 *
 * Uses day-based proration: the customer pays only for the
 * remaining portion of the billing cycle at the new rate,
 * and receives credit for unused time at the old rate.
 */
#[Internal]
final readonly class ProrationCalculator
{
    /**
     * Calculate the prorated charge for a plan change.
     *
     * Returns the net amount to charge (positive) or credit (negative as zero, since
     * Money cannot be negative: credit is tracked separately).
     *
     * @return array{charge: Money, credit: Money}
     */
    #[NoDiscard]
    public function calculate(
        Subscription $subscription,
        Money $newPrice,
        DateTimeImmutable $changeDate,
    ): array {
        $periodStart = $subscription->currentPeriodStart ?? $subscription->createdAt;
        $periodEnd = $subscription->currentPeriodEnd ?? $subscription->billingCycle->nextDate($periodStart);

        $totalDays = max(1, $periodStart->diff($periodEnd)->days);
        $remainingDays = max(0, $changeDate->diff($periodEnd)->days);

        // Credit for unused time at old price
        $creditBasisPoints = (int) round(($remainingDays / $totalDays) * 10000);
        $credit = $subscription->amount->percentage($creditBasisPoints, RoundingMode::Floor);

        // Charge for remaining time at new price
        $chargeBasisPoints = (int) round(($remainingDays / $totalDays) * 10000);
        $charge = $newPrice->percentage($chargeBasisPoints, RoundingMode::Ceiling);

        return ['charge' => $charge, 'credit' => $credit];
    }
}
