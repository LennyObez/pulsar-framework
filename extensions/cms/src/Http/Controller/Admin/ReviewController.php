<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\PublishingStateMachine;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Workflow\EditorialReview;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function is_string;

/**
 * Admin controller for editorial review workflow.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class ReviewController
{
    public function __construct(
        private EditorialWorkflowServiceInterface $workflowService,
        private ContentRepositoryInterface $contentRepository,
        private PublishingStateMachine $publishingStateMachine,
        private GateInterface $gate,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.approve');

        $reviewerId = $request->getQueryParams()['reviewer_id'] ?? null;
        $reviews = $this->workflowService->getPendingReviews(
            is_string($reviewerId) ? $reviewerId : null,
        );

        return Response::json([
            'reviews' => array_map(static fn(EditorialReview $r) => [
                'id' => $r->id,
                'content_id' => $r->contentId,
                'locale' => $r->locale,
                'requested_by' => $r->requestedBy,
                'reviewer_id' => $r->reviewerId,
                'status' => $r->status->value,
                'created_at' => $r->createdAt->format('c'),
            ], $reviews),
        ]);
    }

    public function approve(ServerRequestInterface $request, string $reviewId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.approve');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = (string) ($body['reason'] ?? 'Approved');

        $review = $this->workflowService->approve($reviewId, $reason);

        // Transition content to Approved status
        $content = $this->contentRepository->findById($review->contentId);

        if ($content !== null) {
            try {
                $updated = $this->publishingStateMachine->transition(
                    $content,
                    PublishingStatus::Approved,
                    $identity->id(),
                    $reason,
                );

                $this->contentRepository->save($updated);
            } catch (CmsException) {
                // Content may have already transitioned
            }
        }

        return Response::json([
            'review_id' => $review->id,
            'content_id' => $review->contentId,
            'status' => $review->status->value,
            'decided_at' => $review->decidedAt?->format('c'),
        ]);
    }

    public function reject(ServerRequestInterface $request, string $reviewId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.approve');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = (string) ($body['reason'] ?? 'Rejected');
        $comment = is_string($body['comment'] ?? null) ? $body['comment'] : null;

        $review = $this->workflowService->reject($reviewId, $reason, $comment);

        // Transition content back to Draft
        $content = $this->contentRepository->findById($review->contentId);

        if ($content !== null) {
            try {
                $updated = $this->publishingStateMachine->transition(
                    $content,
                    PublishingStatus::Draft,
                    $identity->id(),
                    $reason,
                );

                $this->contentRepository->save($updated);
            } catch (CmsException) {
                // Content may have already transitioned
            }
        }

        return Response::json([
            'review_id' => $review->id,
            'content_id' => $review->contentId,
            'status' => $review->status->value,
            'comment' => $review->comment,
            'decided_at' => $review->decidedAt?->format('c'),
        ]);
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw new RuntimeException('Authentication required');
        }

        return $identity;
    }

    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw new RuntimeException('Permission denied');
        }
    }
}
