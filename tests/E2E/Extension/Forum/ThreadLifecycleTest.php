<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\ForumService;
use Pulsar\Extension\Forum\Thread\Thread;

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

        // Author deletes own thread; not a moderator action.
        $stack->forumService->deleteThread($thread->id, 'user-alice');

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
 * @internal Stack wiring for the thread-lifecycle E2E test.
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
