<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Comments;

use Pulsar\Api\Api;

/**
 * Moderation lifecycle status for comments.
 *
 * Only comments in Pending status may transition to other states.
 * Once moderated (Approved, Rejected, Spam), the status is final.
 *
 * @psalm-api Public enum referenced by Comment::status and consumed by
 *            user-land code and admin moderation views.
 * @api
 */
#[Api(since: '1.0.0')]
enum ModerationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';

    /**
     * Whether a transition from this status to the target is valid.
     *
     * Only Pending comments can be transitioned to Approved, Rejected, or Spam.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        return $this === self::Pending;
    }

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Spam => 'Spam',
        };
    }
}
