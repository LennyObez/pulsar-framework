<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Publishing\PublishingOrchestratorInterface;

/**
 * Validates and applies publishing status transitions on Content aggregates.
 *
 * Encapsulates the state machine logic for both standard and editorial
 * workflow modes, returning a new Content instance via clone-with.
 *
 * When a PublishingOrchestrator is provided, multi-channel publishing is
 * triggered automatically after successful publish/unpublish transitions.
 */
#[Internal]
final readonly class PublishingStateMachine
{
    public function __construct(
        private ?PublishingOrchestratorInterface $orchestrator = null,
    ) {}

    /**
     * Transition content to the target publishing status.
     *
     * @param string      $actorId          The ID of the user performing the transition
     * @param string|null $reason           Optional reason for the transition (used in audit trail)
     * @param bool        $editorialWorkflow Whether editorial workflow rules apply
     *
     * @throws CmsException If the transition is not allowed from the current status
     */
    public function transition(
        Content $content,
        PublishingStatus $target,
        string $actorId,
        ?string $reason = null,
        bool $editorialWorkflow = false,
    ): Content {
        if (!$content->status->canTransitionTo($target, $editorialWorkflow)) {
            throw CmsException::invalidTransition($content->status->value, $target->value);
        }

        $now = new DateTimeImmutable();

        $result = new Content(
            id: $content->id,
            tenantId: $content->tenantId,
            contentType: $content->contentType,
            authorId: $content->authorId,
            status: $target,
            scheduledPublishAt: match ($target) {
                PublishingStatus::Draft, PublishingStatus::Published => null,
                default => $content->scheduledPublishAt,
            },
            scheduledUnpublishAt: match ($target) {
                PublishingStatus::Draft => null,
                default => $content->scheduledUnpublishAt,
            },
            publishedAt: match ($target) {
                PublishingStatus::Published => $content->publishedAt ?? $now,
                default => $content->publishedAt,
            },
            createdAt: $content->createdAt,
            updatedAt: $now,
            deletedAt: $content->deletedAt,
            template: $content->template,
            parentId: $content->parentId,
            sortOrder: $content->sortOrder,
            commentPolicy: $content->commentPolicy,
            dataClassification: $content->dataClassification,
            version: $content->version,
        );

        // Trigger multi-channel publishing after successful transitions
        if ($target === PublishingStatus::Published) {
            $this->orchestrator?->publishToAll($result);
        } elseif ($target === PublishingStatus::Archived || $target === PublishingStatus::Draft) {
            // Unpublish from channels when content is archived or reverted to draft
            if ($content->status === PublishingStatus::Published) {
                $this->orchestrator?->unpublishFromAll($result);
            }
        }

        return $result;
    }
}
