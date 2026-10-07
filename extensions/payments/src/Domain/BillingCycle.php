<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Billing cycle intervals for recurring subscriptions.
 * @api
 */
#[Api(since: '1.0.0')]
enum BillingCycle: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual = 'annual';

    /**
     * Calculate the next billing date from the given date.
     */
    #[NoDiscard]
    public function nextDate(DateTimeImmutable $from): DateTimeImmutable
    {
        $interval = match ($this) {
            self::Weekly => '+1 week',
            self::Monthly => '+1 month',
            self::Quarterly => '+3 months',
            self::SemiAnnual => '+6 months',
            self::Annual => '+1 year',
        };

        /** @var DateTimeImmutable */
        return $from->modify($interval);
    }

    /**
     * Number of days in this billing cycle (approximate).
     */
    public function approximateDays(): int
    {
        return match ($this) {
            self::Weekly => 7,
            self::Monthly => 30,
            self::Quarterly => 90,
            self::SemiAnnual => 182,
            self::Annual => 365,
        };
    }
}
