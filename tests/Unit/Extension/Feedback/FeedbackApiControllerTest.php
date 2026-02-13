<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Feedback;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
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
use Pulsar\Extension\Feedback\Http\Controller\Api\FeedbackApiController;
use Pulsar\Extension\Feedback\Internal\FeedbackService;
use Pulsar\Http\Message\Response;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(FeedbackApiController::class)]
final class FeedbackApiControllerTest extends TestCase
{
    private FeedbackRepositoryInterface&Stub $repository;
    private FeedbackService $service;
    private FeedbackApiController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(FeedbackRepositoryInterface::class);
        $this->service = new FeedbackService($this->repository);
        $this->controller = new FeedbackApiController($this->service, $this->repository);
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
        string $userId = 'user-1',
        FeedbackCategory $category = FeedbackCategory::Bug,
        string $description = 'Something broke when I clicked the button',
        FeedbackStatus $status = FeedbackStatus::Received,
        ?string $adminResponse = null,
        ?string $githubIssueUrl = null,
    ): Feedback {
        $now = new DateTimeImmutable('2026-03-09T12:00:00+00:00');

        return new Feedback(
            id: $id,
            userId: $userId,
            category: $category,
            description: $description,
            context: ['page' => '/dashboard'],
            status: $status,
            adminResponse: $adminResponse,
            githubIssueUrl: $githubIssueUrl,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    #[Test]
    public function submitFeedbackReturns201(): void
    {
        $this->repository->method('countByUserToday')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'category' => 'bug',
            'description' => 'Something broke when I clicked the button',
            'context' => ['page' => '/dashboard'],
        ]);

        $response = $this->controller->submit($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertIsString($data['id']);
        self::assertSame('bug', $data['category']);
        self::assertSame('received', $data['status']);
        self::assertSame('Something broke when I clicked the button', $data['description']);
    }

    #[Test]
    public function indexReturnsPaginatedFeedback(): void
    {
        $fb1 = $this->makeFeedback(id: 'fb-001');
        $fb2 = $this->makeFeedback(id: 'fb-002', category: FeedbackCategory::Feature);

        $paginationResult = new PaginationResult(
            items: [$fb1, $fb2],
            total: 2,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
            lastPage: 1,
        );

        $this->repository->method('findByUser')->willReturn($paginationResult);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getQueryParams')->willReturn(['page' => '1', 'per_page' => '20']);

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertCount(2, $data);

        $first = $data[0];
        self::assertIsArray($first);
        self::assertSame('fb-001', $first['id']);

        $second = $data[1];
        self::assertIsArray($second);
        self::assertSame('fb-002', $second['id']);

        $pagination = $body['pagination'];
        self::assertIsArray($pagination);
        self::assertSame(20, $pagination['per_page']);
        self::assertFalse($pagination['has_more']);
    }

    #[Test]
    public function showFeedbackReturnsDetail(): void
    {
        $feedback = $this->makeFeedback(
            adminResponse: 'We are looking into it',
            githubIssueUrl: 'https://github.com/example/repo/issues/42',
        );

        $this->repository->method('findById')->willReturn($feedback);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');

        $response = $this->controller->show($request, 'fb-001');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('fb-001', $data['id']);
        self::assertSame('We are looking into it', $data['admin_response']);
        self::assertSame('https://github.com/example/repo/issues/42', $data['github_issue_url']);
    }

    #[Test]
    public function submitWithInvalidCategoryReturns422(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'category' => 'nonexistent',
            'description' => 'A valid description that is long enough',
        ]);

        $response = $this->controller->submit($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);

        $details = $body['details'];
        self::assertIsArray($details);
        $category = $details['category'];
        self::assertIsString($category);
        self::assertStringContainsString('Invalid category', $category);
    }

    #[Test]
    public function submitWithShortDescriptionReturns422(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'category' => 'bug',
            'description' => 'short',
        ]);

        $response = $this->controller->submit($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);

        $details = $body['details'];
        self::assertIsArray($details);
        $description = $details['description'];
        self::assertIsString($description);
        self::assertStringContainsString('between 10 and 5000', $description);
    }

    #[Test]
    public function submitWhenRateLimitExceededReturns429(): void
    {
        $this->repository->method('countByUserToday')->willReturn(10);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'category' => 'bug',
            'description' => 'This is a valid description for the feedback',
        ]);

        $response = $this->controller->submit($request);

        self::assertSame(429, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Rate limit exceeded', $error);
    }

    #[Test]
    public function submitWithoutAuthenticationReturns401(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);

        $response = $this->controller->submit($request);

        self::assertSame(401, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Authentication required', $body['error']);
    }

    #[Test]
    public function showOtherUsersFeedbackReturns404(): void
    {
        $feedback = $this->makeFeedback(userId: 'user-other');

        $this->repository->method('findById')->willReturn($feedback);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');

        $response = $this->controller->show($request, 'fb-001');

        self::assertSame(404, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Feedback not found', $body['error']);
    }

    #[Test]
    public function showNonexistentFeedbackReturns404(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');

        $response = $this->controller->show($request, 'fb-nonexistent');

        self::assertSame(404, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Feedback not found', $body['error']);
    }

    #[Test]
    public function indexDefaultsToPage1AndPerPage20(): void
    {
        $paginationResult = new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
            lastPage: 1,
        );

        /** @var FeedbackRepositoryInterface&MockObject $mock */
        $mock = $this->createMock(FeedbackRepositoryInterface::class);
        $mock->expects(self::once())
            ->method('findByUser')
            ->with('user-1', 1, 20)
            ->willReturn($paginationResult);

        $service = new FeedbackService($this->createStub(FeedbackRepositoryInterface::class));
        $controller = new FeedbackApiController($service, $mock);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getQueryParams')->willReturn([]);

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function indexClampsPerPageTo100(): void
    {
        $paginationResult = new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 100,
            currentPage: 1,
            lastPage: 1,
        );

        /** @var FeedbackRepositoryInterface&MockObject $mock */
        $mock = $this->createMock(FeedbackRepositoryInterface::class);
        $mock->expects(self::once())
            ->method('findByUser')
            ->with('user-1', 1, 100)
            ->willReturn($paginationResult);

        $service = new FeedbackService($this->createStub(FeedbackRepositoryInterface::class));
        $controller = new FeedbackApiController($service, $mock);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getQueryParams')->willReturn(['per_page' => '500']);

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function submitWithEmptyCategoryReturns422(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'category' => '',
            'description' => 'This is a perfectly valid description',
        ]);

        $response = $this->controller->submit($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);
    }

    #[Test]
    public function submitVerifiesFeedbackIsSaved(): void
    {
        /** @var FeedbackRepositoryInterface&MockObject $repo */
        $repo = $this->createMock(FeedbackRepositoryInterface::class);
        $repo->method('countByUserToday')->willReturn(0);
        $repo->expects(self::once())->method('save');

        $service = new FeedbackService($repo);
        $controller = new FeedbackApiController($service, $repo);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'category' => 'bug',
            'description' => 'A description that passes controller validation and gets saved',
        ]);

        $response = $controller->submit($request);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function submitWithContextIncludesItInResponse(): void
    {
        $this->repository->method('countByUserToday')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'category' => 'improvement',
            'description' => 'It would be nice to have a dark mode option',
            'context' => ['page' => '/settings', 'browser' => 'Firefox'],
        ]);

        $response = $this->controller->submit($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['context']);
    }
}
