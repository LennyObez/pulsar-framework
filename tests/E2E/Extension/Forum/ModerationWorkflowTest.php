<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Event\ReportSubmitted;
use Pulsar\Extension\Forum\Event\UserBanned;
use Pulsar\Extension\Forum\Event\UserUnbanned;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\ForumService;
use Pulsar\Extension\Forum\Internal\Service\ModerationService;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Report\PostReport;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReport;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;

use function array_filter;
use function array_slice;
use function array_values;
use function count;

/**
 * E2E: Moderation workflow — report -> review -> action (ban/delete/resolve) with full lifecycle.
 */
#[CoversClass(ModerationService::class)]
#[CoversClass(ThreadReport::class)]
#[CoversClass(PostReport::class)]
#[CoversClass(ForumProfile::class)]
#[Group('e2e-forum')]
final class ModerationWorkflowTest extends TestCase
{
    #[Test]
    public function fullReportLifecyclePendingThroughActioned(): void
    {
        $stack = $this->createModerationStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-offender',
            title: 'Offensive thread content',
            slug: 'offensive-thread-content',
            type: ThreadType::Discussion,
            body: 'Bad content here',
            bodyHtml: '<p>Bad content here</p>',
            ipHash: 'iphash-report',
            userAgentHash: 'uahash-report',
        );

        // Step 1: User submits report
        $report = $stack->moderationService->submitThreadReport(
            threadId: $thread->id,
            reporterId: 'user-reporter',
            reason: 'Inappropriate language',
        );

        self::assertNotEmpty($report->id);
        self::assertSame($thread->id, $report->threadId);
        self::assertSame('user-reporter', $report->reporterId);
        self::assertSame(ReportStatus::Pending, $report->status);
        self::assertNull($report->moderatorId);

        // Step 2: Moderator starts review (Pending -> UnderReview)
        $underReview = $stack->moderationService->startThreadReportReview(
            reportId: $report->id,
            moderatorId: 'mod-01',
            note: 'Investigating the reported content',
        );

        self::assertSame(ReportStatus::UnderReview, $underReview->status);
        self::assertSame('mod-01', $underReview->moderatorId);
        self::assertNotNull($underReview->reviewedAt);

        // Step 3: Moderator resolves (UnderReview -> Actioned)
        $resolved = $stack->moderationService->resolveThreadReport(
            reportId: $report->id,
            moderatorId: 'mod-01',
            note: 'Confirmed violation, content removed',
        );

        self::assertSame(ReportStatus::Actioned, $resolved->status);
        self::assertTrue($resolved->status->isTerminal());

        // Verify the reporter earned the BugHunter badge
        self::assertTrue($stack->badges->hasBadge('user-reporter', Badge::BugHunter));
    }

    #[Test]
    public function dismissReportAsFalsePositive(): void
    {
        $stack = $this->createModerationStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Legitimate thread',
            slug: 'legitimate-thread',
            type: ThreadType::Discussion,
            body: 'Perfectly fine content',
            bodyHtml: '<p>Perfectly fine content</p>',
            ipHash: 'iphash-dismiss',
            userAgentHash: 'uahash-dismiss',
        );

        $report = $stack->moderationService->submitThreadReport(
            threadId: $thread->id,
            reporterId: 'user-bob',
            reason: 'I disagree with this opinion',
        );

        // Dismiss directly (Pending -> Dismissed)
        $dismissed = $stack->moderationService->reviewThreadReport(
            reportId: $report->id,
            status: ReportStatus::Dismissed,
            moderatorId: 'mod-01',
            note: 'Disagreement is not a valid report reason',
        );

        self::assertSame(ReportStatus::Dismissed, $dismissed->status);
        self::assertTrue($dismissed->status->isTerminal());

        // BugHunter badge should NOT be awarded for dismissed reports
        self::assertFalse($stack->badges->hasBadge('user-bob', Badge::BugHunter));
    }

    #[Test]
    public function duplicateReportBySameUserIsPrevented(): void
    {
        $stack = $this->createModerationStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Duplicate report target',
            slug: 'duplicate-report-target',
            type: ThreadType::Discussion,
            body: 'Content',
            bodyHtml: '<p>Content</p>',
            ipHash: 'iphash-dupreport',
            userAgentHash: 'uahash-dupreport',
        );

        $stack->moderationService->submitThreadReport(
            threadId: $thread->id,
            reporterId: 'user-bob',
            reason: 'First report',
        );

        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('already has a pending report');

        $stack->moderationService->submitThreadReport(
            threadId: $thread->id,
            reporterId: 'user-bob',
            reason: 'Duplicate attempt',
        );
    }

    #[Test]
    public function banAndUnbanUserWorkflow(): void
    {
        $stack = $this->createModerationStack();

        // Ensure the user has a forum profile (via thread creation)
        $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-bad-actor',
            title: 'Some thread',
            slug: 'some-thread',
            type: ThreadType::Discussion,
            body: 'Content',
            bodyHtml: '<p>Content</p>',
            ipHash: 'iphash-ban',
            userAgentHash: 'uahash-ban',
        );

        // Ban the user
        $banned = $stack->moderationService->banUser(
            userId: 'user-bad-actor',
            reason: 'Repeated violations of community guidelines',
            moderatorId: 'mod-01',
        );

        self::assertTrue($banned->isBanned);
        self::assertSame('Repeated violations of community guidelines', $banned->banReason);
        self::assertNotNull($banned->bannedAt);
        self::assertNull($banned->banExpiresAt, 'Permanent ban has no expiry');

        // Verify UserBanned event
        $banEvents = array_filter(
            $stack->events->getDispatched(),
            static fn(object $e) => $e instanceof UserBanned,
        );
        self::assertNotEmpty($banEvents);

        // Unban the user
        $unbanned = $stack->moderationService->unbanUser(
            userId: 'user-bad-actor',
            moderatorId: 'mod-01',
        );

        self::assertFalse($unbanned->isBanned);
        self::assertNull($unbanned->banReason);
        self::assertNull($unbanned->bannedAt);

        // Verify UserUnbanned event
        $unbanEvents = array_filter(
            $stack->events->getDispatched(),
            static fn(object $e) => $e instanceof UserUnbanned,
        );
        self::assertNotEmpty($unbanEvents);
    }

    #[Test]
    public function banAlreadyBannedUserThrowsException(): void
    {
        $stack = $this->createModerationStack();

        $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-trouble',
            title: 'Trouble thread',
            slug: 'trouble-thread',
            type: ThreadType::Discussion,
            body: 'Content',
            bodyHtml: '<p>Content</p>',
            ipHash: 'iphash-dblban',
            userAgentHash: 'uahash-dblban',
        );

        $stack->moderationService->banUser(
            userId: 'user-trouble',
            reason: 'First ban',
            moderatorId: 'mod-01',
        );

        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('banned');

        $stack->moderationService->banUser(
            userId: 'user-trouble',
            reason: 'Double ban attempt',
            moderatorId: 'mod-01',
        );
    }

    #[Test]
    public function postReportSubmissionAndReview(): void
    {
        $stack = $this->createModerationStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Thread with bad reply',
            slug: 'thread-with-bad-reply',
            type: ThreadType::Discussion,
            body: 'Legitimate content',
            bodyHtml: '<p>Legitimate content</p>',
            ipHash: 'iphash-postreport',
            userAgentHash: 'uahash-postreport',
        );

        $post = $stack->forumService->createPost(
            threadId: $thread->id,
            authorId: 'user-offender',
            body: 'Offensive reply targeting another user',
            bodyHtml: '<p>Offensive reply targeting another user</p>',
            ipHash: 'iphash-postreport2',
            userAgentHash: 'uahash-postreport2',
        );

        // Submit post report
        $report = $stack->moderationService->submitPostReport(
            postId: $post->id,
            reporterId: 'user-charlie',
            reason: 'Harassment and personal attacks',
        );

        self::assertNotEmpty($report->id);
        self::assertSame($post->id, $report->postId);
        self::assertSame(ReportStatus::Pending, $report->status);

        // Start review
        $underReview = $stack->moderationService->startPostReportReview(
            reportId: $report->id,
            moderatorId: 'mod-02',
            note: 'Reviewing the reported post',
        );

        self::assertSame(ReportStatus::UnderReview, $underReview->status);

        // Resolve the report
        $resolved = $stack->moderationService->resolvePostReport(
            reportId: $report->id,
            moderatorId: 'mod-02',
            note: 'Confirmed harassment, post removed',
        );

        self::assertSame(ReportStatus::Actioned, $resolved->status);
        self::assertTrue($resolved->status->isTerminal());

        // Verify events dispatched
        $reportSubmittedEvents = array_filter(
            $stack->events->getDispatched(),
            static fn(object $e) => $e instanceof ReportSubmitted,
        );
        self::assertNotEmpty($reportSubmittedEvents);
    }

    private function createModerationStack(): ModerationWorkflowStack
    {
        $threads = new E2EThreadRepository();
        $posts = new E2EPostRepository();
        $profiles = new E2EForumProfileRepository();
        $events = new E2EEventDispatcher();
        $config = ForumConfig::fromArray([]);
        $badges = new E2EBadgeService();
        $reputationService = new E2EReputationService($profiles);
        $threadReports = new E2EModerationThreadReportRepository();
        $postReports = new E2EModerationPostReportRepository();

        $forumService = new ForumService(
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            reputationService: $reputationService,
            badgeService: $badges,
            events: $events,
            config: $config,
        );

        $moderationService = new ModerationService(
            threadReports: $threadReports,
            postReports: $postReports,
            profiles: $profiles,
            badgeService: $badges,
            events: $events,
        );

        return new ModerationWorkflowStack(
            forumService: $forumService,
            moderationService: $moderationService,
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            badges: $badges,
            events: $events,
        );
    }
}

/**
 * @internal Shared stack for moderation workflow E2E tests.
 */
final readonly class ModerationWorkflowStack
{
    public function __construct(
        public ForumService $forumService,
        public ModerationService $moderationService,
        public E2EThreadRepository $threads,
        public E2EPostRepository $posts,
        public E2EForumProfileRepository $profiles,
        public E2EBadgeService $badges,
        public E2EEventDispatcher $events,
    ) {}
}

/**
 * @internal In-memory thread report repository for moderation workflow E2E tests.
 */
final class E2EModerationThreadReportRepository implements ThreadReportRepositoryInterface
{
    /** @var array<string, ThreadReport> */
    private array $reports = [];

    #[Override]
    public function findById(string $id): ?ThreadReport
    {
        return $this->reports[$id] ?? null;
    }

    #[Override]
    public function findByReporterAndThread(string $reporterId, string $threadId): ?ThreadReport
    {
        foreach ($this->reports as $report) {
            if ($report->reporterId === $reporterId && $report->threadId === $threadId && !$report->status->isTerminal()) {
                return $report;
            }
        }

        return null;
    }

    #[Override]
    public function findByThread(string $threadId): array
    {
        return array_values(array_filter(
            $this->reports,
            static fn(ThreadReport $r) => $r->threadId === $threadId,
        ));
    }

    #[Override]
    public function findByStatus(
        ReportStatus $status,
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult {
        $items = array_values(array_filter(
            $this->reports,
            static fn(ThreadReport $r) => $r->status === $status,
        ));
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function countPendingForThread(string $threadId): int
    {
        return count(array_filter(
            $this->reports,
            static fn(ThreadReport $r) => $r->threadId === $threadId && $r->status === ReportStatus::Pending,
        ));
    }

    #[Override]
    public function save(ThreadReport $report): void
    {
        $this->reports[$report->id] = $report;
    }

    #[Override]
    public function delete(ThreadReport $report): void
    {
        unset($this->reports[$report->id]);
    }
}

/**
 * @internal In-memory post report repository for moderation workflow E2E tests.
 */
final class E2EModerationPostReportRepository implements PostReportRepositoryInterface
{
    /** @var array<string, PostReport> */
    private array $reports = [];

    #[Override]
    public function findById(string $id): ?PostReport
    {
        return $this->reports[$id] ?? null;
    }

    #[Override]
    public function findByReporterAndPost(string $reporterId, string $postId): ?PostReport
    {
        foreach ($this->reports as $report) {
            if ($report->reporterId === $reporterId && $report->postId === $postId && !$report->status->isTerminal()) {
                return $report;
            }
        }

        return null;
    }

    #[Override]
    public function findByPost(string $postId): array
    {
        return array_values(array_filter(
            $this->reports,
            static fn(PostReport $r) => $r->postId === $postId,
        ));
    }

    #[Override]
    public function findByStatus(
        ReportStatus $status,
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult {
        $items = array_values(array_filter(
            $this->reports,
            static fn(PostReport $r) => $r->status === $status,
        ));
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function countPendingForPost(string $postId): int
    {
        return count(array_filter(
            $this->reports,
            static fn(PostReport $r) => $r->postId === $postId && $r->status === ReportStatus::Pending,
        ));
    }

    #[Override]
    public function save(PostReport $report): void
    {
        $this->reports[$report->id] = $report;
    }

    #[Override]
    public function delete(PostReport $report): void
    {
        unset($this->reports[$report->id]);
    }
}
