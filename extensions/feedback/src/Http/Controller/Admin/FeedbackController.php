<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Feedback\Feedback;
use Pulsar\Extension\Feedback\FeedbackCategory;
use Pulsar\Extension\Feedback\FeedbackRepositoryInterface;
use Pulsar\Extension\Feedback\FeedbackStatus;
use Pulsar\Extension\Feedback\Internal\FeedbackService;
use Pulsar\Extension\Feedback\Job\CreateGitHubIssueJob;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Queue\QueueDriverInterface;

use function array_map;
use function is_string;
use function json_encode;
use function max;
use function min;

use const JSON_THROW_ON_ERROR;

/**
 * Admin controller for feedback triage, response, and GitHub issue creation.
 */
#[Internal(reason: 'Feedback admin controller; implementation detail')]
final readonly class FeedbackController
{
    public function __construct(
        private FeedbackService $service,
        private FeedbackRepositoryInterface $repository,
        private ?HttpClientInterface $httpClient = null,
        private string $githubToken = '',
        private ?QueueDriverInterface $queueDriver = null,
    ) {}

    /**
     * GET /admin/feedback: List all feedback with optional category/status filters.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        /** @var int|string $rawPage */
        $rawPage = $params['page'] ?? 1;
        $page = max(1, (int) $rawPage);

        /** @var int|string $rawPerPage */
        $rawPerPage = $params['per_page'] ?? 20;
        $perPage = min(100, max(1, (int) $rawPerPage));

        $categoryFilter = is_string($params['category'] ?? null)
            ? FeedbackCategory::tryFrom($params['category'])
            : null;

        $statusFilter = is_string($params['status'] ?? null)
            ? FeedbackStatus::tryFrom($params['status'])
            : null;

        $result = $this->repository->findAll($page, $perPage, $categoryFilter, $statusFilter);

        return Response::json([
            'data' => array_map(self::serialize(...), $result->items),
            'pagination' => $result->metaToArray(),
        ]);
    }

    /**
     * GET /admin/feedback/{id}: Show a single feedback item.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $feedback = $this->repository->findById($id);

        if ($feedback === null) {
            return Response::json(['error' => 'Feedback not found'], 404);
        }

        return Response::json([
            'data' => self::serialize($feedback),
        ]);
    }

    /**
     * PUT /admin/feedback/{id}/status: Update feedback status.
     *
     * Request body: { "status": "investigating" | "resolved" | "wont_fix" | "duplicate" }
     */
    public function updateStatus(ServerRequestInterface $request, string $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $statusValue = is_string($body['status'] ?? null) ? $body['status'] : '';
        $status = FeedbackStatus::tryFrom($statusValue);

        if ($status === null) {
            return Response::json([
                'error' => 'Validation failed',
                'details' => [
                    'status' => 'Invalid status. Must be one of: received, investigating, resolved, wont_fix, duplicate',
                ],
            ], 422);
        }

        $feedback = $this->service->updateStatus($id, $status);

        if ($feedback === null) {
            return Response::json(['error' => 'Feedback not found'], 404);
        }

        return Response::json([
            'data' => self::serialize($feedback),
        ]);
    }

    /**
     * POST /admin/feedback/{id}/respond: Attach an admin response.
     *
     * Request body: { "response": "string" }
     */
    public function respond(ServerRequestInterface $request, string $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $response = is_string($body['response'] ?? null) ? $body['response'] : '';

        if ($response === '') {
            return Response::json([
                'error' => 'Validation failed',
                'details' => ['response' => 'Response text is required'],
            ], 422);
        }

        $feedback = $this->service->addResponse($id, $response);

        if ($feedback === null) {
            return Response::json(['error' => 'Feedback not found'], 404);
        }

        return Response::json([
            'data' => self::serialize($feedback),
        ]);
    }

    /**
     * POST /admin/feedback/{id}/github-issue: Queue GitHub issue creation.
     *
     * Request body: { "github_repo": "owner/repo" }
     */
    public function createIssue(ServerRequestInterface $request, string $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $githubRepo = is_string($body['github_repo'] ?? null) ? $body['github_repo'] : '';

        if ($githubRepo === '') {
            return Response::json([
                'error' => 'Validation failed',
                'details' => ['github_repo' => 'GitHub repository (owner/repo) is required'],
            ], 422);
        }

        $feedback = $this->repository->findById($id);

        if ($feedback === null) {
            return Response::json(['error' => 'Feedback not found'], 404);
        }

        if ($feedback->githubIssueUrl !== null) {
            return Response::json([
                'error' => 'A GitHub issue is already linked to this feedback',
                'data' => ['github_issue_url' => $feedback->githubIssueUrl],
            ], 409);
        }

        if ($this->queueDriver !== null) {
            $payload = json_encode([
                'feedback_id' => $id,
                'github_repo' => $githubRepo,
            ], JSON_THROW_ON_ERROR);

            $this->queueDriver->push(
                'feedback',
                CreateGitHubIssueJob::class,
                $payload,
            );
        } elseif ($this->httpClient !== null && $this->githubToken !== '') {
            $job = new CreateGitHubIssueJob(
                $this->repository,
                $this->httpClient,
                $id,
                $githubRepo,
                $this->githubToken,
            );
            $job->handle();
        }

        return Response::json([
            'data' => [
                'feedback_id' => $id,
                'github_repo' => $githubRepo,
                'status' => 'queued',
            ],
        ], 202);
    }

    /**
     * Serialize a feedback entity for the admin API.
     *
     * @return array<string, mixed>
     */
    private static function serialize(Feedback $feedback): array
    {
        return [
            'id' => $feedback->id,
            'user_id' => $feedback->userId,
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
