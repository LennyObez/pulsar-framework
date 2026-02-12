<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Domain;

use Pulsar\Api\Api;

/**
 * Report lifecycle status with state-machine transitions.
 */
#[Api(since: '1.0.0')]
enum ReportStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Actioned = 'actioned';
    case Dismissed = 'dismissed';

    /**
     * Whether a transition from this status to the target is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        return match ($this) {
            self::Pending => match ($target) {
                self::UnderReview, self::Dismissed => true,
                default => false,
            },
            self::UnderReview => match ($target) {
                self::Actioned, self::Dismissed => true,
                default => false,
            },
            self::Actioned, self::Dismissed => false,
        };
    }

    /**
     * Whether the report is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Actioned, self::Dismissed => true,
            default => false,
        };
    }

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under Review',
            self::Actioned => 'Actioned',
            self::Dismissed => 'Dismissed',
        };
    }
}
