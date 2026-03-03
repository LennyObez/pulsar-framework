<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Http\Controller\Api;

use InvalidArgumentException;
use OverflowException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Feedback\Feedback;
use Pulsar\Extension\Feedback\FeedbackCategory;
use Pulsar\Extension\Feedback\FeedbackRepositoryInterface;
use Pulsar\Extension\Feedback\Internal\FeedbackService;
use Pulsar\Http\Message\Response;

use function array_map;
use function is_array;
use function is_string;
use function max;
use function mb_strlen;
use function min;

/**
 * Public API controller for user feedback submission and retrieval.
 *
 * Users can submit feedback, list their own submissions, and view
 * individual feedback items they own.
 */
#[Internal(reason: 'Feedback HTTP controller; implementation detail')]
final readonly class FeedbackApiController
{
    public function __construct(
        private FeedbackService $service,
        private FeedbackRepositoryInterface $repository,
    ) {}

    /**
     * POST /api/v1/feedback: Submit new feedback.
     *
     * Request body:
     * - category: string (required, valid FeedbackCategory value)
     * - description: string (required, 10-5000 chars)
     * - context: object (optional, arbitrary key-value pairs)
     *
     * Returns 201 with feedback data, 422 on validation error, 429 on rate limit.
     */
    public function submit(ServerRequestInterface $request): Response
    {
        /** @var string|null $userId */
        $userId = $request->getAttribute('user_id');

        if ($userId === null || $userId === '') {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        // Validate category
        $categoryValue = is_string($body['category'] ?? null) ? $body['category'] : '';
        $category = FeedbackCategory::tryFrom($categoryValue);

        if ($category === null) {
            return Response::json([
                'error' => 'Validation failed',
                'details' => [
                    'category' => 'Invalid category. Must be one of: bug, feature, improvement, question, other',
                ],
            ], 422);
        }

        // Validate description
        $description = is_string($body['description'] ?? null) ? $body['description'] : '';
        $descriptionLength = mb_strlen($description);

        if ($descriptionLength < 10 || $descriptionLength > 5000) {
            return Response::json([
                'error' => 'Validation failed',
                'details' => [
                    'description' => 'Description must be between 10 and 5000 characters',
                ],
            ], 422);
        }

        // Extract context
        /** @var array<string, mixed> $context */
        $context = is_array($body['context'] ?? null) ? $body['context'] : [];

        try {
            $feedback = $this->service->submit($userId, $category, $description, $context);

            return Response::json([
                'data' => self::serialize($feedback),
            ], 201);
        } catch (OverflowException $e) {
            return Response::json(['error' => $e->getMessage()], 429);
        } catch (InvalidArgumentException $e) {
            return Response::json([
                'error' => 'Validation failed',
                'details' => ['description' => $e->getMessage()],
            ], 422);
        }
    }

    /**
     * GET /api/v1/feedback: List the authenticated user's feedback, paginated.
     */
    public function index(ServerRequestInterface $request): Response
    {
        /** @var string|null $userId */
        $userId = $request->getAttribute('user_id');

        if ($userId === null || $userId === '') {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $params = $request->getQueryParams();

        /** @var int|string $rawPage */
        $rawPage = $params['page'] ?? 1;
        $page = max(1, (int) $rawPage);

        /** @var int|string $rawPerPage */
        $rawPerPage = $params['per_page'] ?? 20;
        $perPage = min(100, max(1, (int) $rawPerPage));

        $result = $this->repository->findByUser($userId, $page, $perPage);

        return Response::json([
            'data' => array_map(self::serialize(...), $result->items),
            'pagination' => $result->metaToArray(),
        ]);
    }

    /**
     * GET /api/v1/feedback/{id}: Show a single feedback item (own only).
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        /** @var string|null $userId */
        $userId = $request->getAttribute('user_id');

        if ($userId === null || $userId === '') {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $feedback = $this->repository->findById($id);

        if ($feedback === null || $feedback->userId !== $userId) {
            return Response::json(['error' => 'Feedback not found'], 404);
        }

        return Response::json([
            'data' => self::serialize($feedback),
        ]);
    }

    /**
     * Serialize a feedback entity for the public API.
     *
     * @return array<string, mixed>
     */
    private static function serialize(Feedback $feedback): array
    {
        return [
            'id' => $feedback->id,
            'category' => $feedback->category->value,
            'description' => $feedback->description,
            'context' => $feedback->context,
            'status' => $feedback->status->value,
            'admin_response' => $feedback->adminResponse,
            'github_issue_url' => $feedback->githubIssueUrl,
            'created_at' => $feedback->createdAt->format('c'),
            'updated_at' => $feedback->updatedAt->format('c'),
        ];
    }
}
