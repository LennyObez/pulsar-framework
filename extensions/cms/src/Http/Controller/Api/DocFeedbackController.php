<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Docs\DocFeedback;
use Pulsar\Extension\Cms\Docs\DocFeedbackRepositoryInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Http\Message\Response;

use function is_bool;
use function is_string;
use function mb_strlen;

/**
 * REST API controller for documentation page feedback.
 *
 * Allows users to submit helpfulness feedback on doc pages
 * and retrieve aggregate feedback summaries.
 */
#[Internal(reason: 'CMS API controller; implementation detail')]
final readonly class DocFeedbackController
{
    public function __construct(
        private DocFeedbackRepositoryInterface $feedbackRepository,
    ) {}

    /**
     * POST /api/v1/cms/docs/feedback: Submit feedback for a doc page.
     */
    public function submit(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) $request->getParsedBody();

        $docPageId = $body['doc_page_id'] ?? null;

        if (!is_string($docPageId) || $docPageId === '') {
            return Response::json(['error' => 'doc_page_id is required'], 400);
        }

        $isHelpful = $body['is_helpful'] ?? null;

        if (!is_bool($isHelpful)) {
            return Response::json(['error' => 'is_helpful is required and must be a boolean'], 400);
        }

        $comment = $body['comment'] ?? null;

        if ($comment !== null) {
            if (!is_string($comment)) {
                return Response::json(['error' => 'comment must be a string'], 400);
            }

            if (mb_strlen($comment) > 2000) {
                return Response::json(['error' => 'comment must not exceed 2000 characters'], 400);
            }
        }

        /** @var string|null $userId */
        $userId = $request->getAttribute('user_id');

        $feedback = DocFeedback::create(
            id: UuidGenerator::v7(),
            docPageId: $docPageId,
            userId: is_string($userId) ? $userId : null,
            isHelpful: $isHelpful,
            comment: is_string($comment) ? $comment : null,
        );

        $this->feedbackRepository->save($feedback);

        return Response::json(['status' => 'created', 'id' => $feedback->id], 201);
    }

    /**
     * GET /api/v1/cms/docs/{docPageId}/feedback: Feedback summary for a doc page.
     */
    public function summary(ServerRequestInterface $request, string $docPageId): Response
    {
        $counts = $this->feedbackRepository->countByDocPage($docPageId);

        return Response::json([
            'data' => [
                'doc_page_id' => $docPageId,
                'helpful' => $counts['helpful'],
                'not_helpful' => $counts['not_helpful'],
                'total' => $counts['helpful'] + $counts['not_helpful'],
            ],
        ]);
    }
}
