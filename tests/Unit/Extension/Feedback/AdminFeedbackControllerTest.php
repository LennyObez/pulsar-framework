<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Feedback;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Feedback\Feedback;
use Pulsar\Extension\Feedback\FeedbackCategory;
use Pulsar\Extension\Feedback\FeedbackRepositoryInterface;
use Pulsar\Extension\Feedback\FeedbackStatus;
use Pulsar\Extension\Feedback\Http\Controller\Admin\FeedbackController;
use Pulsar\Extension\Feedback\Internal\FeedbackService;
use Pulsar\Http\Message\Response;
use Pulsar\Queue\QueueDriverInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class AdminFeedbackControllerTest extends TestCase
{
    private FeedbackRepositoryInterface&Stub $repository;
    private FeedbackService $service;
    private FeedbackController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(FeedbackRepositoryInterface::class);
        $this->service = new FeedbackService($this->repository);
        $this->controller = new FeedbackController($this->service, $this->repository);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(Response $response): array
    {
        $decoded = json_decode($response->getBody()->__toString(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> */
        return $decoded;
    }

    private function makeFeedback(
        string $id = 'fb-001',
        FeedbackStatus $status = FeedbackStatus::Received,
        ?string $adminResponse = null,
        ?string $githubIssueUrl = null,
    ): Feedback {
        $now = new DateTimeImmutable('2026-03-09T12:00:00+00:00');

        return new Feedback(
            id: $id,
            userId: 'user-1',
            category: FeedbackCategory::Bug,
            description: 'The login page crashes on iOS Safari',
            context: ['page' => '/login'],
            status: $status,
            adminResponse: $adminResponse,
            githubIssueUrl: $githubIssueUrl,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    // --- index() tests ---

    #[Test]
    public function indexReturnsPaginatedFeedback(): void
    {
        $fb1 = $this->makeFeedback('fb-001');
        $fb2 = $this->makeFeedback('fb-002');
        $result = new PaginationResult(items: [$fb1, $fb2], total: 2, hasMore: false, perPage: 20);
        $this->repository->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertCount(2, $data);
        self::assertIsArray($body['pagination']);
    }

    #[Test]
    public function indexFiltersByCategoryAndStatus(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20);
        $this->repository->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'category' => 'bug',
            'status' => 'investigating',
        ]);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function indexClampsPaginationValues(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 100);
        $this->repository->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['page' => '-1', 'per_page' => '500']);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function indexIgnoresInvalidCategoryAndStatus(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20);
        $this->repository->method('findAll')->willReturn($result);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'category' => 'invalid',
            'status' => 'nonexistent',
        ]);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // --- show() tests ---

    #[Test]
    public function showReturnsFeedbackById(): void
    {
        $feedback = $this->makeFeedback();
        $this->repository->method('findById')->willReturn($feedback);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $this->controller->show('fb-001');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('fb-001', $data['id']);
        self::assertSame('user-1', $data['user_id']);
        self::assertSame('bug', $data['category']);
        self::assertSame('received', $data['status']);
    }

    #[Test]
    public function showReturns404ForMissingFeedback(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $this->controller->show('missing');

        self::assertSame(404, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Feedback not found', $body['error']);
    }

    // --- updateStatus() tests ---

    #[Test]
    public function updateStatusTransitionsToNewStatus(): void
    {
        $feedback = $this->makeFeedback();
        $this->repository->method('findById')->willReturn($feedback);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['status' => 'investigating']);

        $response = $this->controller->updateStatus($request, 'fb-001');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('investigating', $data['status']);
    }

    #[Test]
    public function updateStatusReturns422ForInvalidStatus(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['status' => 'invalid']);

        $response = $this->controller->updateStatus($request, 'fb-001');

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);
        $details = $body['details'];
        self::assertIsArray($details);
        $statusError = $details['status'];
        self::assertIsString($statusError);
        self::assertStringContainsString('Invalid status', $statusError);
    }

    #[Test]
    public function updateStatusReturns422ForEmptyStatus(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['status' => '']);

        $response = $this->controller->updateStatus($request, 'fb-001');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function updateStatusReturns404ForMissingFeedback(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['status' => 'resolved']);

        $response = $this->controller->updateStatus($request, 'missing');

        self::assertSame(404, $response->getStatusCode());
    }

    // --- respond() tests ---

    #[Test]
    public function respondAttachesAdminResponse(): void
    {
        $feedback = $this->makeFeedback();
        $this->repository->method('findById')->willReturn($feedback);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['response' => 'Fixed in v2.1.0']);

        $response = $this->controller->respond($request, 'fb-001');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('Fixed in v2.1.0', $data['admin_response']);
    }

    #[Test]
    public function respondReturns422ForEmptyResponse(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['response' => '']);

        $response = $this->controller->respond($request, 'fb-001');

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);
        $details = $body['details'];
        self::assertIsArray($details);
        $responseError = $details['response'];
        self::assertIsString($responseError);
        self::assertStringContainsString('required', $responseError);
    }

    #[Test]
    public function respondReturns422ForMissingResponseField(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);

        $response = $this->controller->respond($request, 'fb-001');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function respondReturns404ForMissingFeedback(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['response' => 'Noted']);

        $response = $this->controller->respond($request, 'missing');

        self::assertSame(404, $response->getStatusCode());
    }

    // --- createIssue() tests ---

    #[Test]
    public function createIssueReturns422ForEmptyGithubRepo(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['github_repo' => '']);

        $response = $this->controller->createIssue($request, 'fb-001');

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);
        $details = $body['details'];
        self::assertIsArray($details);
        $repoError = $details['github_repo'];
        self::assertIsString($repoError);
        self::assertStringContainsString('required', $repoError);
    }

    #[Test]
    public function createIssueReturns404ForMissingFeedback(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['github_repo' => 'org/repo']);

        $response = $this->controller->createIssue($request, 'missing');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function createIssueReturns409WhenAlreadyLinked(): void
    {
        $feedback = $this->makeFeedback(githubIssueUrl: 'https://github.com/org/repo/issues/1');
        $this->repository->method('findById')->willReturn($feedback);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['github_repo' => 'org/repo']);

        $response = $this->controller->createIssue($request, 'fb-001');

        self::assertSame(409, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('already linked', $error);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame(
            'https://github.com/org/repo/issues/1',
            $data['github_issue_url'],
        );
    }

    #[Test]
    public function createIssueQueuesJobWhenQueueDriverAvailable(): void
    {
        $feedback = $this->makeFeedback();
        $this->repository->method('findById')->willReturn($feedback);

        /** @var QueueDriverInterface&MockObject $queueDriver */
        $queueDriver = $this->createMock(QueueDriverInterface::class);
        $queueDriver->expects(self::once())->method('push');

        $controller = new FeedbackController($this->service, $this->repository, queueDriver: $queueDriver);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['github_repo' => 'org/repo']);

        $response = $controller->createIssue($request, 'fb-001');

        self::assertSame(202, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('queued', $data['status']);
        self::assertSame('fb-001', $data['feedback_id']);
    }

    #[Test]
    public function createIssueReturns202WithoutQueueDriver(): void
    {
        $feedback = $this->makeFeedback();
        $this->repository->method('findById')->willReturn($feedback);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['github_repo' => 'org/repo']);

        $response = $this->controller->createIssue($request, 'fb-001');

        self::assertSame(202, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('queued', $data['status']);
    }

    // --- serialization tests ---

    #[Test]
    public function serializationIncludesAllExpectedFields(): void
    {
        $feedback = $this->makeFeedback(
            adminResponse: 'We are investigating',
            githubIssueUrl: 'https://github.com/org/repo/issues/42',
        );
        $this->repository->method('findById')->willReturn($feedback);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $this->controller->show('fb-001');

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('user_id', $data);
        self::assertArrayHasKey('category', $data);
        self::assertArrayHasKey('description', $data);
        self::assertArrayHasKey('context', $data);
        self::assertArrayHasKey('status', $data);
        self::assertArrayHasKey('admin_response', $data);
        self::assertArrayHasKey('github_issue_url', $data);
        self::assertArrayHasKey('created_at', $data);
        self::assertArrayHasKey('updated_at', $data);
    }
}
