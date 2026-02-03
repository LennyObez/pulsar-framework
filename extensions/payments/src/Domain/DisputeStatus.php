<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Dispute lifecycle status.
 *
 * State machine:
 *   Open --> UnderReview --> Won | Lost
 *   Open --> Accepted
 */
#[Api]
enum DisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Won = 'won';
    case Lost = 'lost';
    case Accepted = 'accepted';

    /**
     * Check if transitioning to the given status is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Open => $target === self::UnderReview || $target === self::Accepted,
            self::UnderReview => $target === self::Won || $target === self::Lost,
            self::Won, self::Lost, self::Accepted => false,
        };
    }
}
