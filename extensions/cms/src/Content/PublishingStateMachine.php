<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Validates and applies publishing status transitions on Content aggregates.
 *
 * Encapsulates the state machine logic for both standard and editorial
 * workflow modes, returning a new Content instance via clone-with.
 */
#[Internal]
final readonly class PublishingStateMachine
{
    /**
     * Transition content to the target publishing status.
     *
     * @throws CmsException If the transition is not allowed from the current status
     */
    public function transition(Content $content, PublishingStatus $target, bool $editorialWorkflow = false): Content
    {
        if (!$content->status->canTransitionTo($target, $editorialWorkflow)) {
            throw CmsException::invalidTransition($content->status->value, $target->value);
        }

        $now = new DateTimeImmutable();

        return clone $content with {
            status: $target,
            updatedAt: $now,
            publishedAt: match ($target) {
                PublishingStatus::Published => $content->publishedAt ?? $now,
                default => $content->publishedAt,
            },
            scheduledPublishAt: match ($target) {
                PublishingStatus::Draft, PublishingStatus::Published => null,
                default => $content->scheduledPublishAt,
            },
            scheduledUnpublishAt: match ($target) {
                PublishingStatus::Draft => null,
                default => $content->scheduledUnpublishAt,
            },
        };
    }
}
