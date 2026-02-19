<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Comments\AntiAbuseHeuristics;
use Pulsar\Extension\Cms\Comments\Comment;
use Pulsar\Extension\Cms\Comments\CommentBodyPolicy;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentService;
use Pulsar\Extension\Cms\Comments\ModerationStatus;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Http\Middleware\CommentAntiAbuseMiddleware;
use Pulsar\Extension\Cms\Http\Middleware\CommentHoneypotMiddleware;
use Pulsar\Extension\Cms\Http\Middleware\CommentRateLimitMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function array_filter;
use function array_values;
use function count;
use function json_decode;

#[CoversClass(CommentService::class)]
#[CoversClass(Comment::class)]
#[CoversClass(ModerationStatus::class)]
#[CoversClass(CommentRateLimitMiddleware::class)]
#[CoversClass(CommentHoneypotMiddleware::class)]
#[CoversClass(CommentAntiAbuseMiddleware::class)]
#[CoversClass(AntiAbuseHeuristics::class)]
final class CommentModerationIntegrationTest extends TestCase
{
    private InMemoryCommentRepository $commentRepo;
    private InMemoryContentRepositoryForComments $contentRepo;
    private StubAuditLogger $auditLogger;
    private CommentService $commentService;

    protected function setUp(): void
    {
        $this->commentRepo = new InMemoryCommentRepository();
        $this->contentRepo = new InMemoryContentRepositoryForComments();
        $this->auditLogger = new StubAuditLogger();

        // Create a real SafeHtmlPolicy with a stub audit logger
        $safeHtmlPolicy = new SafeHtmlPolicy($this->auditLogger);
        $bodyPolicy = new CommentBodyPolicy($safeHtmlPolicy);

        $this->commentService = new CommentService(
            $this->commentRepo,
            $this->contentRepo,
            $bodyPolicy,
            $this->auditLogger,
        );

        // Seed a published content item for comments
        $content = Content::create(
            id: 'content-for-comments',
            contentType: ContentType::Article,
            authorId: 'author-001',
        )->publish();
        $this->contentRepo->save($content);
    }

    #[Test]
    public function test_guest_submits_comment_pending_status(): void
    {
        $comment = $this->commentService->submit(
            contentId: 'content-for-comments',
            body: '<p>Great article!</p>',
            authorId: null,
            guestName: 'Jane Doe',
            guestEmail: 'jane@example.com',
            ipHash: 'iphash123',
            userAgentHash: 'uahash456',
        );

        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame(ModerationStatus::Pending, $comment->status);
        self::assertSame('content-for-comments', $comment->contentId);
        self::assertNull($comment->authorId);
        self::assertSame('Jane Doe', $comment->guestName);
        self::assertSame('jane@example.com', $comment->guestEmail);
        self::assertTrue($comment->isPending());

        // Should be in the repository
        $found = $this->commentRepo->findById($comment->id);
        self::assertNotNull($found);
    }

    #[Test]
    public function test_authenticated_user_submits_comment(): void
    {
        $comment = $this->commentService->submit(
            contentId: 'content-for-comments',
            body: '<p>Nice post!</p>',
            authorId: 'user-123',
            guestName: null,
            guestEmail: null,
            ipHash: 'iphash789',
            userAgentHash: 'uahash012',
        );

        self::assertSame('user-123', $comment->authorId);
        self::assertNull($comment->guestName);
        self::assertSame(ModerationStatus::Pending, $comment->status);
    }

    #[Test]
    public function test_moderator_approves_comment_with_audit_event(): void
    {
        $comment = $this->commentService->submit(
            contentId: 'content-for-comments',
            body: '<p>Approve me!</p>',
            authorId: null,
            guestName: 'Bob',
            guestEmail: null,
            ipHash: 'iphash',
            userAgentHash: 'uahash',
        );

        $approved = $this->commentService->approve($comment->id, 'moderator-001', 'Looks good');

        self::assertSame(ModerationStatus::Approved, $approved->status);
        self::assertTrue($approved->isApproved());

        // Verify audit event was logged
        $auditEntries = $this->auditLogger->getEntries();
        $moderationEntry = array_filter($auditEntries, static fn(array $e) => $e['action'] === 'cms.comment.approved');
        self::assertNotEmpty($moderationEntry);
    }

    #[Test]
    public function test_moderator_rejects_comment_with_reason_and_audit(): void
    {
        $comment = $this->commentService->submit(
            contentId: 'content-for-comments',
            body: '<p>Reject me!</p>',
            authorId: null,
            guestName: 'Troll',
            guestEmail: null,
            ipHash: 'iphash',
            userAgentHash: 'uahash',
        );

        $rejected = $this->commentService->reject($comment->id, 'moderator-001', 'Inappropriate content');

        self::assertSame(ModerationStatus::Rejected, $rejected->status);

        $auditEntries = $this->auditLogger->getEntries();
        $rejectionEntry = array_values(array_filter(
            $auditEntries,
            static fn(array $e) => $e['action'] === 'cms.comment.rejected',
        ));
        self::assertNotEmpty($rejectionEntry);
        /** @var array<string, mixed> $metadata */
        $metadata = $rejectionEntry[0]['metadata'] ?? [];
        self::assertSame('Inappropriate content', $metadata['reason']);
    }

    #[Test]
    public function test_moderator_marks_comment_as_spam(): void
    {
        $comment = $this->commentService->submit(
            contentId: 'content-for-comments',
            body: '<p>Buy cheap stuff!</p>',
            authorId: null,
            guestName: 'Spammer',
            guestEmail: null,
            ipHash: 'iphash',
            userAgentHash: 'uahash',
        );

        $spam = $this->commentService->markSpam($comment->id, 'moderator-001', 'Commercial spam');

        self::assertSame(ModerationStatus::Spam, $spam->status);
    }

    #[Test]
    public function test_rate_limiting_exceeds_threshold_returns_429(): void
    {
        $cache = new CommentTestTaggedCache();
        $middleware = new CommentRateLimitMiddleware($cache, rateLimitPerMinute: 2);

        $request = $this->createCommentRequest('10.0.0.1');
        $handler = new PassThroughHandler();

        // First 2 requests should pass
        $response1 = $middleware->process($request, $handler);
        self::assertSame(200, $response1->getStatusCode());

        $response2 = $middleware->process($request, $handler);
        self::assertSame(200, $response2->getStatusCode());

        // Third request should be rate-limited
        $response3 = $middleware->process($request, $handler);
        self::assertSame(429, $response3->getStatusCode());
        self::assertTrue($response3->hasHeader('Retry-After'));
        self::assertSame('0', $response3->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function test_honeypot_filled_returns_fake_success(): void
    {
        $middleware = new CommentHoneypotMiddleware($this->auditLogger);

        $request = $this->createCommentRequest('10.0.0.2', [
            'body' => 'My comment',
            'website_url' => 'http://spam.example.com', // Honeypot field filled
        ]);
        $handler = new PassThroughHandler();

        $response = $middleware->process($request, $handler);

        // Should return 200 fake success to fool the bot
        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('success', $data['status'] ?? null);
    }

    #[Test]
    public function test_honeypot_empty_passes_through(): void
    {
        $middleware = new CommentHoneypotMiddleware($this->auditLogger);

        $request = $this->createCommentRequest('10.0.0.3', [
            'body' => 'My comment',
            'website_url' => '', // Honeypot field empty — legitimate user
        ]);
        $handler = new PassThroughHandler();

        $response = $middleware->process($request, $handler);

        // Handler was called → normal response
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function test_anti_abuse_duplicate_body_rejected(): void
    {
        $cache = new CommentTestTaggedCache();
        $heuristics = new AntiAbuseHeuristics($cache);
        $middleware = new CommentAntiAbuseMiddleware($heuristics);

        $request = $this->createCommentRequest('10.0.0.4', ['body' => 'Duplicate comment text']);
        $handler = new PassThroughHandler();

        // First submission passes
        $response1 = $middleware->process($request, $handler);
        self::assertSame(200, $response1->getStatusCode());

        // Second identical submission from same IP is rejected
        $response2 = $middleware->process($request, $handler);
        self::assertSame(422, $response2->getStatusCode());

        /** @var array{message?: string} $data2 */
        $data2 = json_decode((string) $response2->getBody(), true);
        self::assertStringContainsString('Duplicate', $data2['message'] ?? '');
    }

    #[Test]
    public function test_anti_abuse_too_many_links_rejected(): void
    {
        $cache = new CommentTestTaggedCache();
        $heuristics = new AntiAbuseHeuristics($cache);
        $middleware = new CommentAntiAbuseMiddleware($heuristics, maxLinks: 2);

        $request = $this->createCommentRequest('10.0.0.5', [
            'body' => 'Check out https://a.com and https://b.com and https://c.com',
        ]);
        $handler = new PassThroughHandler();

        $response = $middleware->process($request, $handler);
        self::assertSame(422, $response->getStatusCode());

        /** @var array{message?: string} $linksData */
        $linksData = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('links', $linksData['message'] ?? '');
    }

    #[Test]
    public function test_edit_within_window_succeeds(): void
    {
        $comment = $this->commentService->submit(
            contentId: 'content-for-comments',
            body: '<p>Original text</p>',
            authorId: 'user-edit',
            guestName: null,
            guestEmail: null,
            ipHash: 'iphash',
            userAgentHash: 'uahash',
        );

        // Edit within the 15-minute window
        $edited = $this->commentService->edit($comment->id, '<p>Updated text</p>');

        self::assertStringContainsString('Updated text', $edited->body);
        self::assertNotNull($edited->editedAt);
    }

    #[Test]
    public function test_edit_after_window_rejected(): void
    {
        // Create a comment with an expired edit window
        $now = new DateTimeImmutable();
        $expired = new Comment(
            id: 'comment-expired',
            tenantId: null,
            contentId: 'content-for-comments',
            parentId: null,
            authorId: 'user-expired',
            guestName: null,
            guestEmail: null,
            body: '<p>Old text</p>',
            status: ModerationStatus::Pending,
            ipHash: 'iphash',
            userAgentHash: 'uahash',
            editedAt: null,
            editWindowExpiresAt: $now->modify('-1 hour'),
            dataClassification: \Pulsar\Extension\Cms\Content\DataClassification::Pii,
            createdAt: $now->modify('-2 hours'),
            deletedAt: null,
        );
        $this->commentRepo->save($expired);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Edit window has expired');

        $this->commentService->edit('comment-expired', '<p>New text</p>');
    }

    #[Test]
    public function test_comment_body_sanitized_scripts_removed(): void
    {
        $comment = $this->commentService->submit(
            contentId: 'content-for-comments',
            body: '<p>Hello</p><script>alert("xss")</script><p>World</p>',
            authorId: null,
            guestName: 'Tester',
            guestEmail: null,
            ipHash: 'iphash',
            userAgentHash: 'uahash',
        );

        self::assertStringNotContainsString('<script>', $comment->body);
        self::assertStringNotContainsString('alert', $comment->body);
        self::assertStringContainsString('Hello', $comment->body);
        self::assertStringContainsString('World', $comment->body);
    }

    #[Test]
    public function test_submit_to_nonexistent_content_throws(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Content not found');

        $this->commentService->submit(
            contentId: 'nonexistent',
            body: '<p>Test</p>',
            authorId: null,
            guestName: 'Test',
            guestEmail: null,
            ipHash: 'iphash',
            userAgentHash: 'uahash',
        );
    }

    #[Test]
    public function test_approve_already_approved_comment_throws(): void
    {
        $comment = $this->commentService->submit(
            contentId: 'content-for-comments',
            body: '<p>Approve twice</p>',
            authorId: null,
            guestName: 'Test',
            guestEmail: null,
            ipHash: 'iphash',
            userAgentHash: 'uahash',
        );

        $this->commentService->approve($comment->id, 'mod', 'ok');

        $this->expectException(CmsException::class);
        $this->commentService->approve($comment->id, 'mod', 'again');
    }

    /**
     * @param array<string, string> $body
     */
    private function createCommentRequest(string $ip, array $body = ['body' => 'Test comment']): ServerRequestInterface
    {
        $stub = $this->createStub(ServerRequestInterface::class);
        $stub->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);
        $stub->method('getParsedBody')->willReturn($body);
        $stub->method('getMethod')->willReturn('POST');
        $stub->method('getHeaders')->willReturn([]);
        $stub->method('hasHeader')->willReturn(false);
        $stub->method('getHeader')->willReturn([]);
        $stub->method('getHeaderLine')->willReturn('');
        $stub->method('getProtocolVersion')->willReturn('1.1');
        $stub->method('getRequestTarget')->willReturn('/');
        $stub->method('getQueryParams')->willReturn([]);
        $stub->method('getCookieParams')->willReturn([]);
        $stub->method('getUploadedFiles')->willReturn([]);
        $stub->method('getAttributes')->willReturn([]);
        $stub->method('getAttribute')->willReturn(null);
        $stub->method('getBody')->willReturn(\Pulsar\Http\Message\Stream::create(''));

        return $stub;
    }
}

final class PassThroughHandler implements RequestHandlerInterface
{
    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::json(['status' => 'ok']);
    }
}

final class InMemoryCommentRepository implements CommentRepositoryInterface
{
    /** @var array<string, Comment> */
    private array $comments = [];

    public function findById(string $id): ?Comment
    {
        return $this->comments[$id] ?? null;
    }

    public function findByContent(
        string $contentId,
        ?ModerationStatus $status = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $items = array_filter(
            $this->comments,
            static fn(Comment $c) => $c->contentId === $contentId
                && ($status === null || $c->status === $status),
        );

        return new PaginationResult(
            items: array_values($items),
            total: count($items),
            hasMore: false,
            perPage: $perPage,
            currentPage: $page,
            lastPage: 1,
        );
    }

    public function findPendingModeration(
        ?string $tenantId = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $items = array_filter(
            $this->comments,
            static fn(Comment $c) => $c->status === ModerationStatus::Pending,
        );

        return new PaginationResult(
            items: array_values($items),
            total: count($items),
            hasMore: false,
            perPage: $perPage,
            currentPage: $page,
            lastPage: 1,
        );
    }

    public function save(Comment $comment): void
    {
        $this->comments[$comment->id] = $comment;
    }

    public function delete(Comment $comment): void
    {
        unset($this->comments[$comment->id]);
    }
}

final class InMemoryContentRepositoryForComments implements ContentRepositoryInterface
{
    /** @var array<string, Content> */
    private array $contents = [];

    public function findById(string $id): ?Content
    {
        return $this->contents[$id] ?? null;
    }

    public function findByPath(string $locale, string $path, ?string $tenantId = null): ?Content
    {
        return null;
    }

    public function findPublished(
        string $locale,
        ?string $contentType = null,
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult {
        return new PaginationResult(items: [], total: 0, hasMore: false, perPage: $perPage);
    }

    public function save(Content $content): void
    {
        $this->contents[$content->id] = $content;
    }

    public function delete(Content $content): void
    {
        unset($this->contents[$content->id]);
    }

    public function findDescendants(string $contentId): array
    {
        return [];
    }
}

final class StubAuditLogger implements AuditLoggerInterface
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        ?string $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        $this->entries[] = [
            'event' => $event,
            'outcome' => $outcome,
            'actor' => $actor,
            'action' => $action,
            'resource' => $resource,
            'metadata' => $metadata,
        ];

        return new AuditEntry(
            id: 'audit-' . count($this->entries),
            event: $event,
            outcome: $outcome,
            actor: $actor ?? '',
            action: $action,
            resource: $resource,
            timestamp: new DateTimeImmutable(),
            metadata: $metadata,
            previousHmac: '',
            hmac: '',
        );
    }

    /** @return list<array<string, mixed>> */
    public function getEntries(): array
    {
        return $this->entries;
    }
}

final class CommentTestTaggedCache implements TaggedCacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function invalidateTag(string $tag): void
    {
        $this->store = [];
    }

    public function invalidateTags(array $tags): void
    {
        $this->store = [];
    }
}
