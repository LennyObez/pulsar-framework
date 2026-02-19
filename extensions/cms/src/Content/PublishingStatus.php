<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Publishing lifecycle status for content items.
 *
 * Encodes valid state transitions for both standard and editorial workflow modes.
 */
#[Api(since: '1.0.0')]
enum PublishingStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * Whether a transition from this status to the target is valid.
     *
     * In standard mode, content bypasses the editorial pipeline entirely.
     * In editorial workflow mode, additional review/approval states are available.
     */
    public function canTransitionTo(self $target, bool $editorialWorkflow = false): bool
    {
        if ($this === $target) {
            return false;
        }

        return match ($this) {
            self::Draft => match ($target) {
                self::Published, self::Scheduled => true,
                self::InReview => $editorialWorkflow,
                default => false,
            },
            self::InReview => match ($target) {
                self::Approved, self::Draft => $editorialWorkflow,
                default => false,
            },
            self::Approved => match ($target) {
                self::Published, self::Scheduled => $editorialWorkflow,
                self::Draft => $editorialWorkflow,
                default => false,
            },
            self::Scheduled => match ($target) {
                self::Published => true,
                default => false,
            },
            self::Published => match ($target) {
                self::Archived => true,
                default => false,
            },
            self::Archived => match ($target) {
                self::Draft => true,
                default => false,
            },
        };
    }

    /**
     * Whether content in this status is visible to the public.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InReview => 'In Review',
            self::Approved => 'Approved',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }
}
