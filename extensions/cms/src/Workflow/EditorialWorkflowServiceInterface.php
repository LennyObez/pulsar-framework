<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Workflow;

use Pulsar\Api\Api;

/**
 * Service interface for the editorial review workflow.
 *
 * Manages the lifecycle of content review requests from submission
 * through approval or rejection.
 */
#[Api(since: '1.0.0')]
interface EditorialWorkflowServiceInterface
{
    /**
     * Submit content for editorial review.
     */
    public function submitForReview(
        string $contentId,
        string $requestedBy,
        ?string $locale = null,
        ?string $reviewerId = null,
    ): EditorialReview;

    /**
     * Approve a pending review.
     */
    public function approve(string $reviewId, string $decisionReason): EditorialReview;

    /**
     * Reject a pending review with feedback.
     */
    public function reject(string $reviewId, string $decisionReason, ?string $comment = null): EditorialReview;

    /**
     * Retrieve all pending reviews, optionally filtered by reviewer.
     *
     * @return list<EditorialReview>
     */
    public function getPendingReviews(?string $reviewerId = null): array;

    /**
     * Cancel a pending review request.
     */
    public function cancelReview(string $reviewId): EditorialReview;
}
