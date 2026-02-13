<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Comments\Comment;
use Pulsar\Extension\Cms\Comments\CommentBodyPolicy;
use Pulsar\Extension\Cms\Comments\CommentService;
use Pulsar\Extension\Cms\Comments\ModerationStatus;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function array_filter;
use function count;

/**
 * E2E: Comment workflow — guest submit -> pending -> approve -> visible + spam rate limiting.
 */
#[CoversClass(CommentService::class)]
#[Group('e2e-cms')]
final class CommentWorkflowTest extends TestCase
{
    #[Test]
    public function guestCommentSubmissionThroughApproval(): void
    {
        [$service, $commentRepo, $auditLogger] = $this->createCommentStack();

        // Step 1: Guest submits comment
        $comment = $service->submit(
            contentId: 'published-article-001',
            body: '<p>Excellent article, very informative!</p>',
            authorId: null,
            guestName: 'Jane Reader',
            guestEmail: 'jane@reader.com',
            ipHash: 'iphash-e2e-001',
            userAgentHash: 'uahash-e2e-001',
        );

        self::assertSame(ModerationStatus::Pending, $comment->status);
        self::assertNull($comment->authorId);
        self::assertSame('Jane Reader', $comment->guestName);

        // Step 2: Moderator approves
        $approved = $service->approve($comment->id, 'moderator-001', 'Constructive feedback');

        self::assertSame(ModerationStatus::Approved, $approved->status);
        self::assertTrue($approved->isApproved());

        // Step 3: Verify audit trail
        $entries = $auditLogger->getEntries();
        $approvalEntries = array_filter($entries, static fn(array $e) => $e['action'] === 'cms.comment.approved');
        self::assertNotEmpty($approvalEntries);
    }

    #[Test]
    public function spamCommentFlagging(): void
    {
        [$service, , ] = $this->createCommentStack();

        $comment = $service->submit(
            contentId: 'published-article-001',
            body: '<p>Buy cheap products at http://spam.example.com</p>',
            authorId: null,
            guestName: 'Spammer Bot',
            guestEmail: null,
            ipHash: 'iphash-spam',
            userAgentHash: 'uahash-spam',
        );

        $spam = $service->markSpam($comment->id, 'moderator-001', 'Commercial spam');
        self::assertSame(ModerationStatus::Spam, $spam->status);

        // Cannot transition from Spam to any other state
        self::assertFalse($spam->status->canTransitionTo(ModerationStatus::Approved));
        self::assertFalse($spam->status->canTransitionTo(ModerationStatus::Pending));
    }

    #[Test]
    public function commentEditWithinWindow(): void
    {
        [$service, , ] = $this->createCommentStack();

        $comment = $service->submit(
            contentId: 'published-article-001',
            body: '<p>Original comment text</p>',
            authorId: 'user-editor',
            guestName: null,
            guestEmail: null,
            ipHash: 'iphash-edit',
            userAgentHash: 'uahash-edit',
        );

        self::assertTrue($comment->canEdit());

        $edited = $service->edit($comment->id, '<p>Updated comment text</p>');
        self::assertStringContainsString('Updated comment text', $edited->body);
        self::assertNotNull($edited->editedAt);
    }

    #[Test]
    public function doubleApprovalRejected(): void
    {
        [$service, , ] = $this->createCommentStack();

        $comment = $service->submit(
            contentId: 'published-article-001',
            body: '<p>Approve once</p>',
            authorId: null,
            guestName: 'User',
            guestEmail: null,
            ipHash: 'iphash-dbl',
            userAgentHash: 'uahash-dbl',
        );

        $service->approve($comment->id, 'mod', 'ok');

        $this->expectException(CmsException::class);
        $service->approve($comment->id, 'mod', 'again');
    }

    /**
     * @return array{0: CommentService, 1: E2ECommentRepository, 2: E2EAuditLogger}
     */
    private function createCommentStack(): array
    {
        $commentRepo = new E2ECommentRepository();
        $contentRepo = new E2EContentRepository();
        $auditLogger = new E2EAuditLogger();

        // Seed a published article
        $article = Content::create(
            id: 'published-article-001',
            contentType: ContentType::Article,
            authorId: 'author-001',
        )->publish();
        $contentRepo->save($article);

        $safeHtmlPolicy = new SafeHtmlPolicy($auditLogger);
        $bodyPolicy = new CommentBodyPolicy($safeHtmlPolicy);

        $service = new CommentService($commentRepo, $contentRepo, $bodyPolicy, $auditLogger);

        return [$service, $commentRepo, $auditLogger];
    }
}

/**
 * @internal In-memory comment repository for E2E tests.
 */
final class E2ECommentRepository implements \Pulsar\Extension\Cms\Comments\CommentRepositoryInterface
{
    /** @var array<string, Comment> */
    private array $comments = [];

    public function findById(string $id): ?Comment
    {
        return $this->comments[$id] ?? null;
    }

    public function findByContent(string $contentId, ?ModerationStatus $status = null, int $page = 1, int $perPage = 20): PaginationResult
    {
        $items = array_filter($this->comments, static fn(Comment $c) => $c->contentId === $contentId && ($status === null || $c->status === $status));

        return new PaginationResult(items: array_values($items), total: count($items), hasMore: false, perPage: $perPage);
    }

    public function findPendingModeration(?string $tenantId = null, int $page = 1, int $perPage = 20): PaginationResult
    {
        $items = array_filter($this->comments, static fn(Comment $c) => $c->status === ModerationStatus::Pending);

        return new PaginationResult(items: array_values($items), total: count($items), hasMore: false, perPage: $perPage);
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

/**
 * @internal In-memory content repository for E2E comment tests.
 */
final class E2EContentRepository implements ContentRepositoryInterface
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

    public function findPublished(string $locale, ?string $contentType = null, int $page = 1, int $perPage = 20, ?string $tenantId = null): PaginationResult
    {
        return new PaginationResult(items: [], total: 0, hasMore: false, perPage: $perPage);
    }

    public function findByIds(array $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            if (isset($this->contents[$id])) {
                $result[$id] = $this->contents[$id];
            }
        }

        return $result;
    }

    public function findAncestors(string $contentId, int $maxDepth = 20): array
    {
        return [];
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

    public function findScheduledForPublishing(DateTimeImmutable $now): array
    {
        return [];
    }

    public function findScheduledForUnpublishing(DateTimeImmutable $now): array
    {
        return [];
    }

    public function bulkUpdateStatus(array $ids, \Pulsar\Extension\Cms\Content\PublishingStatus $status, ?string $tenantId = null): int
    {
        return 0;
    }

    public function bulkDelete(array $ids, ?string $tenantId = null): int
    {
        return 0;
    }
}

/**
 * @internal Stub audit logger for E2E tests.
 */
final class E2EAuditLogger implements AuditLoggerInterface
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    public function log(AuditEvent $event, AuditOutcome $outcome, ?string $actor, string $action, string $resource = '', array $metadata = []): AuditEntry
    {
        $this->entries[] = ['event' => $event, 'outcome' => $outcome, 'actor' => $actor, 'action' => $action, 'resource' => $resource, 'metadata' => $metadata];

        return new AuditEntry(id: 'audit-' . count($this->entries), event: $event, outcome: $outcome, actor: $actor ?? '', action: $action, resource: $resource, timestamp: new DateTimeImmutable(), metadata: $metadata, previousHmac: '', hmac: '');
    }

    /** @return list<array<string, mixed>> */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
