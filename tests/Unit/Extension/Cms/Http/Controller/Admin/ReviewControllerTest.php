<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\PublishingStateMachine;
use Pulsar\Extension\Cms\Http\Controller\Admin\ReviewController;
use Pulsar\Extension\Cms\Workflow\EditorialReview;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Extension\Cms\Workflow\ReviewStatus;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ReviewController::class)]
final class ReviewControllerTest extends TestCase
{
    #[Test]
    public function index_returns_pending_reviews(): void
    {
        $review = new EditorialReview(
            id: 'review-1',
            contentId: 'content-1',
            locale: 'en',
            requestedBy: 'author-1',
            reviewerId: 'editor-1',
            status: ReviewStatus::Pending,
            comment: null,
            decisionReason: null,
            createdAt: new DateTimeImmutable('2026-03-01T12:00:00+00:00'),
            decidedAt: null,
        );

        $workflowService = $this->createStub(EditorialWorkflowServiceInterface::class);
        $workflowService->method('getPendingReviews')->willReturn([$review]);

        $controller = $this->createController(workflowService: $workflowService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $reviews */
        $reviews = $body['reviews'];
        self::assertCount(1, $reviews);
        self::assertSame('review-1', $reviews[0]['id']);
        self::assertSame('content-1', $reviews[0]['content_id']);
        self::assertSame('pending', $reviews[0]['status']);
    }

    #[Test]
    public function index_passes_reviewer_id_filter(): void
    {
        $workflowService = $this->createMock(EditorialWorkflowServiceInterface::class);
        $workflowService->expects(self::once())
            ->method('getPendingReviews')
            ->with('editor-42')
            ->willReturn([]);

        $controller = $this->createController(workflowService: $workflowService);
        $request = $this->createAuthenticatedRequest(queryParams: ['reviewer_id' => 'editor-42']);

        $controller->index($request);
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $controller = $this->createController();

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->createController(gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    #[Test]
    public function approve_returns_review_decision(): void
    {
        $decidedAt = new DateTimeImmutable('2026-03-02T14:00:00+00:00');
        $approvedReview = new EditorialReview(
            id: 'review-1',
            contentId: 'content-1',
            locale: 'en',
            requestedBy: 'author-1',
            reviewerId: 'editor-1',
            status: ReviewStatus::Approved,
            comment: null,
            decisionReason: 'Looks good',
            createdAt: new DateTimeImmutable('2026-03-01T12:00:00+00:00'),
            decidedAt: $decidedAt,
        );

        $workflowService = $this->createStub(EditorialWorkflowServiceInterface::class);
        $workflowService->method('approve')->willReturn($approvedReview);

        $contentRepository = $this->createStub(ContentRepositoryInterface::class);
        $contentRepository->method('findById')->willReturn(null);

        $controller = $this->createController(
            workflowService: $workflowService,
            contentRepository: $contentRepository,
        );

        $request = $this->createAuthenticatedRequest(parsedBody: ['reason' => 'Looks good']);
        $response = $controller->approve($request, 'review-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('review-1', $body['review_id']);
        self::assertSame('content-1', $body['content_id']);
        self::assertSame('approved', $body['status']);
        self::assertSame($decidedAt->format('c'), $body['decided_at']);
    }

    #[Test]
    public function reject_returns_review_with_comment(): void
    {
        $decidedAt = new DateTimeImmutable('2026-03-02T14:00:00+00:00');
        $rejectedReview = new EditorialReview(
            id: 'review-2',
            contentId: 'content-2',
            locale: null,
            requestedBy: 'author-1',
            reviewerId: null,
            status: ReviewStatus::Rejected,
            comment: 'Needs more detail in section 3',
            decisionReason: 'Incomplete content',
            createdAt: new DateTimeImmutable('2026-03-01T12:00:00+00:00'),
            decidedAt: $decidedAt,
        );

        $workflowService = $this->createStub(EditorialWorkflowServiceInterface::class);
        $workflowService->method('reject')->willReturn($rejectedReview);

        $contentRepository = $this->createStub(ContentRepositoryInterface::class);
        $contentRepository->method('findById')->willReturn(null);

        $controller = $this->createController(
            workflowService: $workflowService,
            contentRepository: $contentRepository,
        );

        $request = $this->createAuthenticatedRequest(parsedBody: [
            'reason' => 'Incomplete content',
            'comment' => 'Needs more detail in section 3',
        ]);

        $response = $controller->reject($request, 'review-2');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('review-2', $body['review_id']);
        self::assertSame('rejected', $body['status']);
        self::assertSame('Needs more detail in section 3', $body['comment']);
    }

    private function createController(
        ?EditorialWorkflowServiceInterface $workflowService = null,
        ?ContentRepositoryInterface $contentRepository = null,
        ?GateInterface $gate = null,
    ): ReviewController {
        return new ReviewController(
            workflowService: $workflowService ?? $this->createStub(EditorialWorkflowServiceInterface::class),
            contentRepository: $contentRepository ?? $this->createStub(ContentRepositoryInterface::class),
            publishingStateMachine: new PublishingStateMachine(),
            gate: $gate,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, string> $queryParams
     */
    private function createAuthenticatedRequest(
        ?array $parsedBody = null,
        array $queryParams = [],
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('editor-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/reviews');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/reviews');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }

}
