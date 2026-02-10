<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\ForumService;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function max;

/**
 * E2E: Full thread lifecycle — create -> edit -> pin -> lock -> close, plus invalid transitions.
 */
#[CoversClass(ForumService::class)]
#[CoversClass(Thread::class)]
#[Group('e2e-forum')]
final class ThreadLifecycleTest extends TestCase
{
    #[Test]
    public function fullThreadLifecycleCreateEditPinLockClose(): void
    {
        $stack = $this->createForumStack();

        // Step 1: Create a new thread
        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Getting started with Pulsar',
            slug: 'getting-started-with-pulsar',
            type: ThreadType::Discussion,
            body: '# Hello\nThis is my first thread.',
            bodyHtml: '<h1>Hello</h1><p>This is my first thread.</p>',
            ipHash: 'iphash-001',
            userAgentHash: 'uahash-001',
        );

        self::assertNotEmpty($thread->id);
        self::assertSame(ThreadStatus::Open, $thread->status);
        self::assertFalse($thread->isPinned);
        self::assertFalse($thread->isLocked);
        self::assertSame(0, $thread->replyCount);

        // Step 2: Edit the thread title
        $edited = $thread->editTitle('Getting started with Pulsar (updated)', 'getting-started-with-pulsar-updated');
        $stack->threads->save($edited);

        $reloaded = $stack->threads->findById($thread->id);
        self::assertNotNull($reloaded);
        self::assertSame('Getting started with Pulsar (updated)', $reloaded->title);
        self::assertSame('getting-started-with-pulsar-updated', $reloaded->slug);

        // Step 3: Pin the thread
        $pinned = $stack->forumService->pinThread($thread->id);
        self::assertTrue($pinned->isPinned);

        // Step 4: Lock the thread
        $locked = $stack->forumService->lockThread($thread->id);
        self::assertTrue($locked->isLocked);

        // Step 5: Close the thread
        $lockedThread = $stack->threads->findById($thread->id);
        self::assertNotNull($lockedThread);
        $closed = $lockedThread->close();
        $stack->threads->save($closed);

        $final = $stack->threads->findById($thread->id);
        self::assertNotNull($final);
        self::assertSame(ThreadStatus::Closed, $final->status);
        self::assertFalse($final->isOpen());
    }

    #[Test]
    public function createThreadSetsCorrectDefaults(): void
    {
        $stack = $this->createForumStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-help',
            authorId: 'user-bob',
            title: 'How to test controllers?',
            slug: 'how-to-test-controllers',
            type: ThreadType::Question,
            body: 'What is the best approach?',
            bodyHtml: '<p>What is the best approach?</p>',
            ipHash: 'iphash-defaults',
            userAgentHash: 'uahash-defaults',
        );

        self::assertSame('cat-help', $thread->categoryId);
        self::assertSame('user-bob', $thread->authorId);
        self::assertSame(ThreadType::Question, $thread->type);
        self::assertSame(ThreadStatus::Open, $thread->status);
        self::assertFalse($thread->isPinned);
        self::assertFalse($thread->isLocked);
        self::assertNull($thread->solvedPostId);
        self::assertSame(0, $thread->viewCount);
        self::assertSame(0, $thread->voteScore);
    }

    #[Test]
    public function closeAndReopenCycle(): void
    {
        $stack = $this->createForumStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Thread to close and reopen',
            slug: 'thread-to-close-and-reopen',
            type: ThreadType::Discussion,
            body: 'Closing and reopening test',
            bodyHtml: '<p>Closing and reopening test</p>',
            ipHash: 'iphash-cycle',
            userAgentHash: 'uahash-cycle',
        );

        // Close
        $closed = $thread->close();
        self::assertSame(ThreadStatus::Closed, $closed->status);
        self::assertFalse($closed->isOpen());

        // Reopen
        $reopened = $closed->reopen();
        self::assertSame(ThreadStatus::Open, $reopened->status);
        self::assertTrue($reopened->isOpen());
        self::assertFalse($reopened->isLocked, 'Reopening should clear the lock');
    }

    #[Test]
    public function invalidStatusTransitionThrowsException(): void
    {
        $stack = $this->createForumStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Cannot reopen while open',
            slug: 'cannot-reopen-while-open',
            type: ThreadType::Discussion,
            body: 'Already open',
            bodyHtml: '<p>Already open</p>',
            ipHash: 'iphash-inv',
            userAgentHash: 'uahash-inv',
        );

        // Open -> Open is not a valid transition
        $this->expectException(ForumException::class);
        $thread->reopen();
    }

    #[Test]
    public function deleteThreadRemovesFromRepository(): void
    {
        $stack = $this->createForumStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Thread to delete',
            slug: 'thread-to-delete',
            type: ThreadType::Discussion,
            body: 'Will be deleted',
            bodyHtml: '<p>Will be deleted</p>',
            ipHash: 'iphash-del',
            userAgentHash: 'uahash-del',
        );

        $stack->forumService->deleteThread($thread->id);

        $reloaded = $stack->threads->findById($thread->id);
        self::assertNull($reloaded, 'Deleted thread should not be retrievable');
    }

    #[Test]
    public function createThreadAwardsReputationAndFirstPostBadge(): void
    {
        $stack = $this->createForumStack();

        $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'My first thread',
            slug: 'my-first-thread',
            type: ThreadType::Discussion,
            body: 'Hello world',
            bodyHtml: '<p>Hello world</p>',
            ipHash: 'iphash-rep',
            userAgentHash: 'uahash-rep',
        );

        // Verify reputation was awarded
        $profile = $stack->profiles->findByUser('user-alice');
        self::assertNotNull($profile);
        self::assertGreaterThan(0, $profile->reputationScore);

        // Verify badge was awarded
        self::assertTrue($stack->badges->hasBadge('user-alice', Badge::FirstPost));
    }

    private function createForumStack(): ForumTestStack
    {
        $threads = new E2EThreadRepository();
        $posts = new E2EPostRepository();
        $profiles = new E2EForumProfileRepository();
        $events = new E2EEventDispatcher();
        $config = ForumConfig::fromArray([]);
        $badges = new E2EBadgeService();
        $reputationService = new E2EReputationService($profiles);

        $forumService = new ForumService(
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            reputationService: $reputationService,
            badgeService: $badges,
            events: $events,
            config: $config,
        );

        return new ForumTestStack(
            forumService: $forumService,
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            badges: $badges,
            events: $events,
        );
    }
}

/**
 * @internal Shared stack for Forum E2E tests.
 */
final readonly class ForumTestStack
{
    public function __construct(
        public ForumService $forumService,
        public E2EThreadRepository $threads,
        public E2EPostRepository $posts,
        public E2EForumProfileRepository $profiles,
        public E2EBadgeService $badges,
        public E2EEventDispatcher $events,
    ) {}
}

/**
 * @internal In-memory thread repository for E2E tests.
 */
final class E2EThreadRepository implements ThreadRepositoryInterface
{
    /** @var array<string, Thread> */
    private array $threads = [];

    #[Override]
    public function findById(string $id): ?Thread
    {
        return $this->threads[$id] ?? null;
    }

    #[Override]
    public function findBySlug(string $slug, ?string $tenantId = null): ?Thread
    {
        foreach ($this->threads as $thread) {
            if ($thread->slug === $slug) {
                return $thread;
            }
        }

        return null;
    }

    #[Override]
    public function findByCategory(
        string $categoryId,
        int $page = 1,
        int $perPage = 25,
        ?\Pulsar\Extension\Forum\Domain\ThreadStatus $status = null,
        ?\Pulsar\Extension\Forum\Domain\ThreadType $type = null,
    ): PaginationResult {
        $items = array_filter($this->threads, static fn(Thread $t) => $t->categoryId === $categoryId && ($status === null || $t->status === $status));
        $items = array_values($items);
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function findByAuthor(string $authorId, int $page = 1, int $perPage = 25): PaginationResult
    {
        $items = array_values(array_filter($this->threads, static fn(Thread $t) => $t->authorId === $authorId));
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function findByTag(string $tagId, int $page = 1, int $perPage = 25): PaginationResult
    {
        /** @var list<Thread> */
        $items = [];

        if (isset($this->tagIndex[$tagId])) {
            foreach ($this->tagIndex[$tagId] as $threadId) {
                if (isset($this->threads[$threadId])) {
                    $items[] = $this->threads[$threadId];
                }
            }
        }

        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function findRecent(int $page = 1, int $perPage = 25, ?string $tenantId = null): PaginationResult
    {
        $items = array_values($this->threads);
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function save(Thread $thread): void
    {
        $this->threads[$thread->id] = $thread;
    }

    #[Override]
    public function delete(Thread $thread): void
    {
        unset($this->threads[$thread->id]);
    }

    #[Override]
    public function incrementVoteScore(string $id, int $delta): void
    {
        if (isset($this->threads[$id])) {
            $this->threads[$id] = $this->threads[$id]->updateVoteScore($delta);
        }
    }

    #[Override]
    public function incrementReplyCount(string $id, int $delta = 1): void
    {
        if (!isset($this->threads[$id])) {
            return;
        }

        if ($delta > 0) {
            for ($i = 0; $i < $delta; $i++) {
                $this->threads[$id] = $this->threads[$id]->incrementReplyCount();
            }
        }
    }

    /**
     * Index a thread under a tag for findByTag() lookups.
     *
     * @internal Test infrastructure only.
     */
    public function indexTag(string $tagId, string $threadId): void
    {
        $this->tagIndex[$tagId][] = $threadId;
    }

    /**
     * Remove a thread from a tag index for findByTag() lookups.
     *
     * @internal Test infrastructure only.
     */
    public function deindexTag(string $tagId, string $threadId): void
    {
        if (!isset($this->tagIndex[$tagId])) {
            return;
        }

        $this->tagIndex[$tagId] = array_values(array_filter(
            $this->tagIndex[$tagId],
            static fn(string $id) => $id !== $threadId,
        ));
    }

    /** @var array<string, list<string>> */
    private array $tagIndex = [];
}

/**
 * @internal In-memory post repository for E2E tests.
 */
final class E2EPostRepository implements PostRepositoryInterface
{
    /** @var array<string, Post> */
    private array $posts = [];

    #[Override]
    public function findById(string $id): ?Post
    {
        return $this->posts[$id] ?? null;
    }

    #[Override]
    public function findByThread(string $threadId, int $page = 1, int $perPage = 20): PaginationResult
    {
        $items = array_values(array_filter($this->posts, static fn(Post $p) => $p->threadId === $threadId && !$p->isDeleted()));
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function findByAuthor(string $authorId, int $page = 1, int $perPage = 20): PaginationResult
    {
        $items = array_values(array_filter($this->posts, static fn(Post $p) => $p->authorId === $authorId));
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return new PaginationResult(items: $paged, total: $total, hasMore: ($offset + $perPage) < $total, perPage: $perPage);
    }

    #[Override]
    public function countByThread(string $threadId): int
    {
        return count(array_filter($this->posts, static fn(Post $p) => $p->threadId === $threadId && !$p->isDeleted()));
    }

    #[Override]
    public function save(Post $post): void
    {
        $this->posts[$post->id] = $post;
    }

    #[Override]
    public function delete(Post $post): void
    {
        unset($this->posts[$post->id]);
    }

    #[Override]
    public function incrementVoteScore(string $id, int $delta): void
    {
        if (isset($this->posts[$id])) {
            $this->posts[$id] = $this->posts[$id]->updateVoteScore($delta);
        }
    }
}

/**
 * @internal In-memory forum profile repository for E2E tests.
 */
final class E2EForumProfileRepository implements ForumProfileRepositoryInterface
{
    /** @var array<string, ForumProfile> */
    private array $profiles = [];

    #[Override]
    public function findById(string $id): ?ForumProfile
    {
        return $this->profiles[$id] ?? null;
    }

    #[Override]
    public function findByUser(string $userId, ?string $tenantId = null): ?ForumProfile
    {
        foreach ($this->profiles as $profile) {
            if ($profile->userId === $userId) {
                return $profile;
            }
        }

        return null;
    }

    #[Override]
    public function findTopContributors(int $page = 1, int $perPage = 20, ?string $tenantId = null): PaginationResult
    {
        return new PaginationResult(items: [], total: 0, hasMore: false, perPage: $perPage);
    }

    #[Override]
    public function save(ForumProfile $profile): void
    {
        $this->profiles[$profile->id] = $profile;
    }

    #[Override]
    public function delete(ForumProfile $profile): void
    {
        unset($this->profiles[$profile->id]);
    }

    #[Override]
    public function incrementReputation(string $userId, int $delta, ?string $tenantId = null): void
    {
        $profile = $this->findByUser($userId, $tenantId);

        if ($profile !== null) {
            $this->profiles[$profile->id] = $profile->addReputation($delta);
        }
    }

    #[Override]
    public function incrementPostCount(string $userId, ?string $tenantId = null, int $delta = 1): void
    {
        $profile = $this->findByUser($userId, $tenantId);

        if ($profile !== null && $delta > 0) {
            for ($i = 0; $i < $delta; $i++) {
                $this->profiles[$profile->id] = $this->profiles[$profile->id]->incrementPostCount();
            }
        }
    }

    #[Override]
    public function incrementThreadCount(string $userId, ?string $tenantId = null, int $delta = 1): void
    {
        $profile = $this->findByUser($userId, $tenantId);

        if ($profile !== null && $delta > 0) {
            for ($i = 0; $i < $delta; $i++) {
                $this->profiles[$profile->id] = $this->profiles[$profile->id]->incrementThreadCount();
            }
        }
    }
}

/**
 * @internal Stub event dispatcher for E2E tests.
 */
final class E2EEventDispatcher implements EventDispatcherInterface, \Pulsar\Event\EventDispatcherInterface
{
    /** @var list<object> */
    private array $dispatched = [];

    #[Override]
    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }

    #[Override]
    public function dispatchEnvelope(\Pulsar\Event\EventEnvelope $envelope): \Pulsar\Event\EventEnvelope
    {
        $this->dispatched[] = $envelope;

        return $envelope;
    }

    /** @return list<object> */
    public function getDispatched(): array
    {
        return $this->dispatched;
    }
}

/**
 * @internal Stub badge service for E2E tests.
 */
final class E2EBadgeService implements BadgeServiceInterface
{
    /** @var array<string, list<Badge>> */
    private array $badges = [];

    #[Override]
    public function evaluate(string $userId, Badge $badge): bool
    {
        return true;
    }

    #[Override]
    public function award(string $userId, Badge $badge, ?string $tenantId = null): ?UserBadge
    {
        if ($this->hasBadge($userId, $badge)) {
            return null;
        }

        $this->badges[$userId][] = $badge;

        return UserBadge::award(
            id: 'badge-' . $userId . '-' . $badge->value,
            userId: $userId,
            badge: $badge,
            tenantId: $tenantId,
        );
    }

    #[Override]
    public function revoke(string $userId, Badge $badge): void
    {
        if (!isset($this->badges[$userId])) {
            return;
        }

        $this->badges[$userId] = array_values(array_filter(
            $this->badges[$userId],
            static fn(Badge $b) => $b !== $badge,
        ));
    }

    #[Override]
    public function getUserBadges(string $userId): array
    {
        return [];
    }

    #[Override]
    public function hasBadge(string $userId, Badge $badge): bool
    {
        if (!isset($this->badges[$userId])) {
            return false;
        }

        foreach ($this->badges[$userId] as $b) {
            if ($b === $badge) {
                return true;
            }
        }

        return false;
    }
}

/**
 * @internal Stub reputation service for E2E tests.
 */
final class E2EReputationService implements ReputationServiceInterface
{
    public function __construct(
        private readonly E2EForumProfileRepository $profiles,
    ) {}

    #[Override]
    public function addReputation(string $userId, int $points, string $reason): ForumProfile
    {
        $profile = $this->getOrCreateProfile($userId);
        $updated = $profile->addReputation($points);
        $this->profiles->save($updated);

        return $updated;
    }

    #[Override]
    public function getLevel(string $userId): ReputationLevel
    {
        $profile = $this->profiles->findByUser($userId);

        return $profile !== null
            ? $profile->reputationLevel()
            : ReputationLevel::Newcomer;
    }

    #[Override]
    public function isEligibleForPromotion(string $userId): bool
    {
        return false;
    }

    #[Override]
    public function getOrCreateProfile(string $userId, ?string $tenantId = null): ForumProfile
    {
        $profile = $this->profiles->findByUser($userId, $tenantId);

        if ($profile === null) {
            $profile = ForumProfile::create(
                id: 'profile-' . $userId,
                userId: $userId,
                tenantId: $tenantId,
            );
            $this->profiles->save($profile);
        }

        return $profile;
    }
}
