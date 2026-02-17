<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Comments\Comment;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentServiceInterface;
use Pulsar\Extension\Cms\Comments\ModerationStatus;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Http\Controller\Admin\CommentController;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(CommentController::class)]
final class CommentControllerTest extends TestCase
{
    #[Test]
    public function index_returns_pending_comments(): void
    {
        $comment = $this->createComment('comment-1', ModerationStatus::Pending);

        $result = new PaginationResult(
            items: [$comment],
            total: 1,
            hasMore: false,
            perPage: 20,
        );

        $repo = $this->createStub(CommentRepositoryInterface::class);
        $repo->method('findPendingModeration')->willReturn($result);

        $service = $this->createStub(CommentServiceInterface::class);
        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $comments */
        $comments = $body['comments'];
        self::assertCount(1, $comments);
        self::assertSame('comment-1', $comments[0]['id']);
        self::assertSame('pending', $comments[0]['status']);
    }

    #[Test]
    public function show_returns_comment_details(): void
    {
        $comment = $this->createComment('comment-1', ModerationStatus::Pending);

        $repo = $this->createStub(CommentRepositoryInterface::class);
        $repo->method('findById')->willReturn($comment);

        $service = $this->createStub(CommentServiceInterface::class);
        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'comment-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('comment-1', $body['id']);
        self::assertSame('content-1', $body['content_id']);
    }

    #[Test]
    public function show_returns_404_when_comment_not_found(): void
    {
        $repo = $this->createStub(CommentRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $service = $this->createStub(CommentServiceInterface::class);
        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function detail_returns_comment_with_parent_context(): void
    {
        $parent = $this->createComment('parent-1', ModerationStatus::Approved);
        $child = $this->createComment('child-1', ModerationStatus::Pending, parentId: 'parent-1');

        $repo = $this->createStub(CommentRepositoryInterface::class);
        $repo->method('findById')->willReturnCallback(
            static fn(string $id): ?Comment => match ($id) {
                'child-1' => $child,
                'parent-1' => $parent,
                default => null,
            },
        );

        $service = $this->createStub(CommentServiceInterface::class);
        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->detail($request, 'child-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $commentData */
        $commentData = $body['comment'];
        self::assertSame('child-1', $commentData['id']);
        self::assertSame('parent-1', $commentData['parent_id']);

        /** @var array<string, mixed>|null $parentData */
        $parentData = $body['parentComment'];
        self::assertNotNull($parentData);
        self::assertSame('parent-1', $parentData['id']);
    }

    #[Test]
    public function detail_returns_null_parent_when_no_parent(): void
    {
        $comment = $this->createComment('comment-1', ModerationStatus::Pending);

        $repo = $this->createStub(CommentRepositoryInterface::class);
        $repo->method('findById')->willReturn($comment);

        $service = $this->createStub(CommentServiceInterface::class);
        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->detail($request, 'comment-1');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($body['parentComment']);
    }

    #[Test]
    public function moderate_approves_comment(): void
    {
        $approved = $this->createComment('comment-1', ModerationStatus::Approved);

        $repo = $this->createStub(CommentRepositoryInterface::class);
        $service = $this->createStub(CommentServiceInterface::class);
        $service->method('approve')->willReturn($approved);

        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'action' => 'approve',
            'reason' => 'Good comment',
        ]);

        $response = $controller->moderate($request, 'comment-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('approved', $body['status']);
        self::assertSame('approve', $body['action']);
    }

    #[Test]
    public function moderate_returns_400_for_invalid_action(): void
    {
        $repo = $this->createStub(CommentRepositoryInterface::class);
        $service = $this->createStub(CommentServiceInterface::class);
        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'action' => 'invalid',
        ]);

        $response = $controller->moderate($request, 'comment-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function moderate_returns_422_on_service_exception(): void
    {
        $repo = $this->createStub(CommentRepositoryInterface::class);
        $service = $this->createStub(CommentServiceInterface::class);
        $service->method('reject')->willThrowException(new CmsException('Invalid transition'));

        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'action' => 'reject',
            'reason' => 'Off-topic',
        ]);

        $response = $controller->moderate($request, 'comment-1');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function delete_removes_comment(): void
    {
        $comment = $this->createComment('comment-1', ModerationStatus::Pending);

        $repo = $this->createStub(CommentRepositoryInterface::class);
        $repo->method('findById')->willReturn($comment);

        $service = $this->createStub(CommentServiceInterface::class);
        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'comment-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['deleted']);
    }

    #[Test]
    public function delete_returns_404_when_not_found(): void
    {
        $repo = $this->createStub(CommentRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $service = $this->createStub(CommentServiceInterface::class);
        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function bulk_action_processes_multiple_comments(): void
    {
        $approved = $this->createComment('c-1', ModerationStatus::Approved);

        $repo = $this->createStub(CommentRepositoryInterface::class);
        $service = $this->createStub(CommentServiceInterface::class);
        $service->method('approve')->willReturn($approved);

        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'bulk_action' => 'approve',
            'ids' => ['c-1', 'c-2', 'c-3'],
        ]);

        $response = $controller->bulkAction($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('approve', $body['action']);
        self::assertSame(3, $body['processed']);
        self::assertSame(0, $body['failed']);
    }

    #[Test]
    public function bulk_action_returns_400_for_invalid_action(): void
    {
        $repo = $this->createStub(CommentRepositoryInterface::class);
        $service = $this->createStub(CommentServiceInterface::class);
        $controller = new CommentController(commentRepository: $repo, commentService: $service);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'bulk_action' => 'destroy',
            'ids' => ['c-1'],
        ]);

        $response = $controller->bulkAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $repo = $this->createStub(CommentRepositoryInterface::class);
        $service = $this->createStub(CommentServiceInterface::class);
        $controller = new CommentController(commentRepository: $repo, commentService: $service);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $repo = $this->createStub(CommentRepositoryInterface::class);
        $service = $this->createStub(CommentServiceInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new CommentController(commentRepository: $repo, commentService: $service, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createComment(
        string $id,
        ModerationStatus $status,
        ?string $parentId = null,
    ): Comment {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new Comment(
            id: $id,
            tenantId: null,
            contentId: 'content-1',
            parentId: $parentId,
            authorId: null,
            guestName: 'Jane Doe',
            guestEmail: 'jane@example.com',
            body: 'A test comment body',
            status: $status,
            ipHash: 'hash_ip',
            userAgentHash: 'hash_ua',
            editedAt: null,
            editWindowExpiresAt: $now->modify('+15 minutes'),
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            deletedAt: null,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(?array $parsedBody = null): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('mod-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/comments');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
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
        $uri->method('getPath')->willReturn('/admin/comments');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
