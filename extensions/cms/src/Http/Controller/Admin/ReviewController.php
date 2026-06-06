<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\PublishingStateMachine;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Workflow\EditorialReview;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_string;

/**
 * Admin controller for editorial review workflow.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class ReviewController extends AbstractAdminController
{
    public function __construct(
        private EditorialWorkflowServiceInterface $workflowService,
        private ContentRepositoryInterface $contentRepository,
        private PublishingStateMachine $publishingStateMachine,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.approve');

        /** @var mixed $reviewerId */
        $reviewerId = $request->getQueryParams()['reviewer_id'] ?? null;
        $reviews = $this->workflowService->getPendingReviews(
            is_string($reviewerId) ? $reviewerId : null,
        );

        $data = [
            'reviews' => array_map(static fn(EditorialReview $r) => [
                'id' => $r->id,
                'content_id' => $r->contentId,
                'locale' => $r->locale,
                'requested_by' => $r->requestedBy,
                'reviewer_id' => $r->reviewerId,
                'status' => $r->status->value,
                'created_at' => $r->createdAt->format('c'),
            ], $reviews),
        ];

        return $this->respondWithView($request, 'admin.reviews.index', $data);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function approve(ServerRequestInterface $request, string $reviewId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.approve');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : 'Approved';

        $review = $this->workflowService->approve($reviewId, $reason);

        // Transition content to Approved status
        $content = $this->contentRepository->findById($review->contentId);

        if ($content !== null) {
            try {
                $updated = $this->publishingStateMachine->transition(
                    $content,
                    PublishingStatus::Approved,
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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function reject(ServerRequestInterface $request, string $reviewId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.approve');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : 'Rejected';
        /** @var mixed $rawComment */
        $rawComment = $body['comment'] ?? null;
        $comment = is_string($rawComment) ? $rawComment : null;

        $review = $this->workflowService->reject($reviewId, $reason, $comment);

        // Transition content back to Draft
        $content = $this->contentRepository->findById($review->contentId);

        if ($content !== null) {
            try {
                $updated = $this->publishingStateMachine->transition(
                    $content,
                    PublishingStatus::Draft,
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

}
