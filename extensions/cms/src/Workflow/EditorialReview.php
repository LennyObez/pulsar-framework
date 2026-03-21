<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Workflow;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Tracks editorial review requests and decisions for content items.
 *
 * Part of the editorial workflow that gates content progression
 * from Draft through Review to Published status.
 *
 * @psalm-api Public DTO returned from EditorialWorkflowServiceInterface;
 *            consumed by review queue templates.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EditorialReview
{
    /**
     * @param string $id UUIDv7
     * @param string $contentId UUIDv7 FK content
     * @param string|null $locale BCP 47 locale code, null = all locales
     * @param string $requestedBy UUIDv7 user who submitted for review
     * @param string|null $reviewerId UUIDv7 assigned reviewer, null = any editor
     * @param ReviewStatus $status Current review status
     * @param string|null $comment Editorial comment from reviewer
     * @param string|null $decisionReason Reason for approval or rejection
     * @param DateTimeImmutable $createdAt When the review was requested
     * @param DateTimeImmutable|null $decidedAt When the review decision was made
     */
    public function __construct(
        public string $id,
        public string $contentId,
        public ?string $locale,
        public string $requestedBy,
        public ?string $reviewerId,
        public ReviewStatus $status,
        public ?string $comment,
        public ?string $decisionReason,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $decidedAt,
    ) {}
}
