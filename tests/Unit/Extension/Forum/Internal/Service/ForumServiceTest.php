<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Event\PostAcceptedAsSolution;
use Pulsar\Extension\Forum\Event\PostCreated;
use Pulsar\Extension\Forum\Event\PostDeleted;
use Pulsar\Extension\Forum\Event\PostEdited;
use Pulsar\Extension\Forum\Event\ThreadCreated;
use Pulsar\Extension\Forum\Event\ThreadDeleted;
use Pulsar\Extension\Forum\Event\ThreadLocked;
use Pulsar\Extension\Forum\Event\ThreadPinned;
use Pulsar\Extension\Forum\Event\ThreadUnlocked;
use Pulsar\Extension\Forum\Event\ThreadUnpinned;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\ForumService;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;

#[CoversClass(ForumService::class)]
final class ForumServiceTest extends TestCase
{
    private ThreadRepositoryInterface&Stub $threads;
    private PostRepositoryInterface&Stub $posts;
    private ForumProfileRepositoryInterface&Stub $profiles;
    private ReputationServiceInterface&Stub $reputationService;
    private BadgeServiceInterface&Stub $badgeService;
    private EventDispatcherInterface&Stub $events;
    private ForumConfig $config;

    protected function setUp(): void
    {
        $this->threads = $this->createStub(ThreadRepositoryInterface::class);
        $this->posts = $this->createStub(PostRepositoryInterface::class);
        $this->profiles = $this->createStub(ForumProfileRepositoryInterface::class);

        $dummyProfile = ForumProfile::create('dummy-id', 'dummy-user');
        $this->reputationService = $this->createStub(ReputationServiceInterface::class);
        $this->reputationService->method('getOrCreateProfile')->willReturn($dummyProfile);
        $this->reputationService->method('addReputation')->willReturn($dummyProfile);

        $this->badgeService = $this->createStub(BadgeServiceInterface::class);
        $this->events = $this->createStub(EventDispatcherInterface::class);
        $this->config = new ForumConfig();
    }

    private function makeService(
        ?ThreadRepositoryInterface $threads = null,
        ?PostRepositoryInterface $posts = null,
        ?ForumProfileRepositoryInterface $profiles = null,
        ?EventDispatcherInterface $events = null,
        ?CategoryRepositoryInterface $categories = null,
    ): ForumService {
        return new ForumService(
            threads: $threads ?? $this->threads,
            posts: $posts ?? $this->posts,
            profiles: $profiles ?? $this->profiles,
            reputationService: $this->reputationService,
            badgeService: $this->badgeService,
            events: $events ?? $this->events,
            config: $this->config,
            categories: $categories,
        );
    }

    #[Test]
    public function createThreadSavesThreadAndPostAndDispatchesEvent(): void
    {
        $threads = $this->createMock(ThreadRepositoryInterface::class);
        $posts = $this->createMock(PostRepositoryInterface::class);
        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threads->expects(self::once())->method('save')->with(self::isInstanceOf(Thread::class));
        $posts->expects(self::once())->method('save')->with(self::isInstanceOf(Post::class));
        $profiles->expects(self::once())->method('incrementThreadCount')->with('author-1', null);
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ThreadCreated::class));

        $service = $this->makeService(threads: $threads, posts: $posts, profiles: $profiles, events: $events);

        $thread = $service->createThread(
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: 'Test Thread',
            slug: 'test-thread',
            type: ThreadType::Discussion,
            body: 'Body markdown',
            bodyHtml: '<p>Body markdown</p>',
            ipHash: 'iphash',
            userAgentHash: 'uahash',
        );

        self::assertSame('cat-1', $thread->categoryId);
        self::assertSame('author-1', $thread->authorId);
        self::assertSame('Test Thread', $thread->title);
        self::assertSame(ThreadType::Discussion, $thread->type);
    }

    #[Test]
    public function createThreadThrowsWhenCategoryNotFound(): void
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn(null);

        $service = $this->makeService(categories: $categories);

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Category not found');

        $service->createThread('cat-999', 'author-1', 'Title', 'slug', ThreadType::Discussion, 'body', '<p>body</p>', 'ip', 'ua');
    }

    #[Test]
    public function createThreadThrowsWhenCategoryLocked(): void
    {
        $category = Category::create(id: 'cat-1', slug: 'general');
        $lockedCategory = $category->lock();

        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn($lockedCategory);

        $service = $this->makeService(categories: $categories);

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Category is locked');

        $service->createThread('cat-1', 'author-1', 'Title', 'slug', ThreadType::Discussion, 'body', '<p>body</p>', 'ip', 'ua');
    }

    #[Test]
    public function createPostSavesPostAndIncrementsCounters(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: 'Thread',
            slug: 'thread',
            type: ThreadType::Discussion,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $threads = $this->createMock(ThreadRepositoryInterface::class);
        $posts = $this->createMock(PostRepositoryInterface::class);
        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threads->method('findById')->willReturn($thread);
        $threads->expects(self::once())->method('incrementReplyCount')->with('thread-1');
        $posts->expects(self::once())->method('save')->with(self::isInstanceOf(Post::class));
        $profiles->expects(self::once())->method('incrementPostCount')->with('author-2', null);
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(PostCreated::class));

        $service = $this->makeService(threads: $threads, posts: $posts, profiles: $profiles, events: $events);

        $post = $service->createPost(
            threadId: 'thread-1',
            authorId: 'author-2',
            body: 'Reply body',
            bodyHtml: '<p>Reply body</p>',
            ipHash: 'ip2',
            userAgentHash: 'ua2',
        );

        self::assertSame('thread-1', $post->threadId);
        self::assertSame('author-2', $post->authorId);
    }

    #[Test]
    public function createPostThrowsWhenThreadNotFound(): void
    {
        $this->threads->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Thread not found');

        $service->createPost('missing', 'author-1', 'body', '<p>body</p>', 'ip', 'ua');
    }

    #[Test]
    public function createPostThrowsWhenThreadIsLocked(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: 'Thread',
            slug: 'thread',
            type: ThreadType::Discussion,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );
        $locked = $thread->lock();

        $this->threads->method('findById')->willReturn($locked);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('locked');

        $service->createPost('thread-1', 'author-2', 'body', '<p>body</p>', 'ip', 'ua');
    }

    #[Test]
    public function editPostUpdatesAndDispatchesEvent(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'author-1',
            body: 'Original',
            bodyHtml: '<p>Original</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $posts = $this->createMock(PostRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $posts->method('findById')->willReturn($post);
        $posts->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(PostEdited::class));

        $service = $this->makeService(posts: $posts, events: $events);

        $edited = $service->editPost('post-1', 'Updated', '<p>Updated</p>', 'author-1');

        self::assertSame('Updated', $edited->body);
        self::assertSame(1, $edited->editCount);
    }

    #[Test]
    public function editPostThrowsWhenPostNotFound(): void
    {
        $this->posts->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Post not found');

        $service->editPost('missing', 'body', '<p>body</p>', 'user-1');
    }

    #[Test]
    public function editPostThrowsWhenNonAuthorAndNotModerator(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'author-1',
            body: 'Original',
            bodyHtml: '<p>Original</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $this->posts->method('findById')->willReturn($post);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Unauthorized');

        $service->editPost('post-1', 'New body', '<p>New body</p>', 'other-user');
    }

    #[Test]
    public function editPostAllowsModeratorToEditOtherUsersPost(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'author-1',
            body: 'Original',
            bodyHtml: '<p>Original</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $posts = $this->createMock(PostRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $posts->method('findById')->willReturn($post);
        $posts->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch');

        $service = $this->makeService(posts: $posts, events: $events);

        $edited = $service->editPost('post-1', 'Moderated', '<p>Moderated</p>', 'moderator-1', isModerator: true);

        self::assertSame('Moderated', $edited->body);
    }

    #[Test]
    public function deletePostRemovesAndDecrementsCounters(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'author-1',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $threads = $this->createMock(ThreadRepositoryInterface::class);
        $posts = $this->createMock(PostRepositoryInterface::class);
        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $posts->method('findById')->willReturn($post);
        $posts->expects(self::once())->method('delete')->with($post);
        $threads->expects(self::once())->method('incrementReplyCount')->with('thread-1', -1);
        $profiles->expects(self::once())->method('incrementPostCount')->with('author-1', null, -1);
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(PostDeleted::class));

        $service = $this->makeService(threads: $threads, posts: $posts, profiles: $profiles, events: $events);

        // Author deletes own post; not a moderator action (MED-4 signature).
        $service->deletePost('post-1', 'author-1');
    }

    #[Test]
    public function deletePostThrowsWhenNotFound(): void
    {
        $this->posts->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Post not found');

        $service->deletePost('missing', 'author-1');
    }

    #[Test]
    public function deleteThreadRemovesAndDecrementsProfile(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: 'Thread',
            slug: 'thread',
            type: ThreadType::Discussion,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $threads = $this->createMock(ThreadRepositoryInterface::class);
        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threads->method('findById')->willReturn($thread);
        $threads->expects(self::once())->method('delete')->with($thread);
        $profiles->expects(self::once())->method('incrementThreadCount')->with('author-1', null, -1);
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ThreadDeleted::class));

        $service = $this->makeService(threads: $threads, profiles: $profiles, events: $events);

        // Author deletes own thread; not a moderator action (MED-4 signature).
        $service->deleteThread('thread-1', 'author-1');
    }

    #[Test]
    public function deleteThreadThrowsWhenNotFound(): void
    {
        $this->threads->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);

        $service->deleteThread('missing', 'author-1');
    }

    #[Test]
    public function acceptSolutionMarksThreadAndPostAndAwardsReputation(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: 'Question',
            slug: 'question',
            type: ThreadType::Question,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'answer-author',
            body: 'Answer',
            bodyHtml: '<p>Answer</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $threads = $this->createMock(ThreadRepositoryInterface::class);
        $posts = $this->createMock(PostRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threads->method('findById')->willReturn($thread);
        $posts->method('findById')->willReturn($post);
        $threads->expects(self::once())->method('save');
        $posts->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(PostAcceptedAsSolution::class));

        $service = $this->makeService(threads: $threads, posts: $posts, events: $events);

        $result = $service->acceptSolution('thread-1', 'post-1');

        self::assertSame('post-1', $result->solvedPostId);
    }

    #[Test]
    public function acceptSolutionThrowsWhenThreadNotFound(): void
    {
        $this->threads->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Thread not found');

        $service->acceptSolution('missing', 'post-1');
    }

    #[Test]
    public function acceptSolutionThrowsWhenPostNotFound(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: 'Q',
            slug: 'q',
            type: ThreadType::Question,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $this->threads->method('findById')->willReturn($thread);
        $this->posts->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Post not found');

        $service->acceptSolution('thread-1', 'missing');
    }

    #[Test]
    public function lockThreadSetsLockedFlagAndDispatchesEvent(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: 'Thread',
            slug: 'thread',
            type: ThreadType::Discussion,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $threads = $this->createMock(ThreadRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threads->method('findById')->willReturn($thread);
        $threads->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ThreadLocked::class));

        $service = $this->makeService(threads: $threads, events: $events);

        $result = $service->lockThread('thread-1');

        self::assertTrue($result->isLocked);
    }

    #[Test]
    public function lockThreadThrowsWhenNotFound(): void
    {
        $this->threads->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);

        $service->lockThread('missing');
    }

    #[Test]
    public function unlockThreadClearsLockedFlag(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: 'Thread',
            slug: 'thread',
            type: ThreadType::Discussion,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );
        $locked = $thread->lock();

        $threads = $this->createMock(ThreadRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threads->method('findById')->willReturn($locked);
        $threads->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ThreadUnlocked::class));

        $service = $this->makeService(threads: $threads, events: $events);

        $result = $service->unlockThread('thread-1');

        self::assertFalse($result->isLocked);
    }

    #[Test]
    public function unlockThreadThrowsWhenNotFound(): void
    {
        $this->threads->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);

        $service->unlockThread('missing');
    }

    #[Test]
    public function pinThreadSetsPinnedFlag(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: 'Thread',
            slug: 'thread',
            type: ThreadType::Discussion,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $threads = $this->createMock(ThreadRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threads->method('findById')->willReturn($thread);
        $threads->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ThreadPinned::class));

        $service = $this->makeService(threads: $threads, events: $events);

        $result = $service->pinThread('thread-1');

        self::assertTrue($result->isPinned);
    }

    #[Test]
    public function pinThreadThrowsWhenNotFound(): void
    {
        $this->threads->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);

        $service->pinThread('missing');
    }

    #[Test]
    public function unpinThreadClearsPinnedFlag(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: 'Thread',
            slug: 'thread',
            type: ThreadType::Discussion,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );
        $pinned = $thread->pin();

        $threads = $this->createMock(ThreadRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threads->method('findById')->willReturn($pinned);
        $threads->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ThreadUnpinned::class));

        $service = $this->makeService(threads: $threads, events: $events);

        $result = $service->unpinThread('thread-1');

        self::assertFalse($result->isPinned);
    }

    #[Test]
    public function unpinThreadThrowsWhenNotFound(): void
    {
        $this->threads->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);

        $service->unpinThread('missing');
    }
}
