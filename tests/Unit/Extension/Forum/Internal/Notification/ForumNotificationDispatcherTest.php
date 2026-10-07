<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Notification;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Event\PostAcceptedAsSolution;
use Pulsar\Extension\Forum\Event\PostCreated;
use Pulsar\Extension\Forum\Event\ReportSubmitted;
use Pulsar\Extension\Forum\Event\VoteCast;
use Pulsar\Extension\Forum\Internal\Notification\ForumNotificationDispatcher;
use Pulsar\Extension\Forum\Notification\ForumNotificationInterface;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Subscription\ThreadSubscription;
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;

#[CoversClass(ForumNotificationDispatcher::class)]
final class ForumNotificationDispatcherTest extends TestCase
{
    private ThreadRepositoryInterface&Stub $threads;
    private ThreadSubscriptionRepositoryInterface&Stub $subscriptions;
    private PostRepositoryInterface&Stub $posts;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->threads = $this->createStub(ThreadRepositoryInterface::class);
        $this->subscriptions = $this->createStub(ThreadSubscriptionRepositoryInterface::class);
        $this->posts = $this->createStub(PostRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeDispatcher(?PostRepositoryInterface $posts = null): ForumNotificationDispatcher
    {
        return new ForumNotificationDispatcher(
            threadRepository: $this->threads,
            subscriptionRepository: $this->subscriptions,
            postRepository: $posts ?? $this->posts,
            logger: $this->logger,
        );
    }

    private function makeThread(string $id = 'thread-1', string $title = 'Test Thread'): Thread
    {
        return Thread::create(
            id: $id,
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: $title,
            slug: 'test-thread',
            type: ThreadType::Discussion,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );
    }

    private function makeSubscription(string $userId, string $threadId = 'thread-1'): ThreadSubscription
    {
        return ThreadSubscription::subscribe(
            id: 'sub-' . $userId,
            userId: $userId,
            threadId: $threadId,
        );
    }

    // --- dispatch() ---

    #[Test]
    public function dispatchLogsNotification(): void
    {
        $notification = $this->createStub(ForumNotificationInterface::class);
        $notification->method('type')->willReturn('test_type');
        $notification->method('subject')->willReturn('Test Subject');
        $notification->method('recipientIds')->willReturn(['user-1']);
        $notification->method('metadata')->willReturn([]);

        $this->logger->expects(self::once())->method('info')->with(
            'Forum notification dispatched',
            self::callback(static fn(array $ctx): bool => $ctx['type'] === 'test_type' && $ctx['subject'] === 'Test Subject'),
        );

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch($notification);
    }

    // --- onPostCreated() ---

    #[Test]
    public function onPostCreatedNotifiesSubscribers(): void
    {
        $thread = $this->makeThread();
        $this->threads->method('findById')->willReturn($thread);
        $this->subscriptions->method('findByThread')->willReturn([
            $this->makeSubscription('subscriber-1'),
        ]);

        $this->logger->expects(self::once())->method('info');

        $dispatcher = $this->makeDispatcher();
        $event = new PostCreated(
            postId: 'post-1',
            threadId: 'thread-1',
            authorId: 'author-2',
            authorDisplayName: 'John',
        );

        $dispatcher->onPostCreated($event);
    }

    #[Test]
    public function onPostCreatedSkipsWhenThreadNotFound(): void
    {
        $this->threads->method('findById')->willReturn(null);
        $this->logger->expects(self::never())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onPostCreated(new PostCreated('p-1', 'thread-missing', 'author-1'));
    }

    #[Test]
    public function onPostCreatedExcludesPostAuthorFromRecipients(): void
    {
        $thread = $this->makeThread();
        $this->threads->method('findById')->willReturn($thread);
        // The only subscriber is the post author
        $this->subscriptions->method('findByThread')->willReturn([
            $this->makeSubscription('author-1'),
        ]);

        $this->logger->expects(self::never())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onPostCreated(new PostCreated('p-1', 'thread-1', 'author-1'));
    }

    #[Test]
    public function onPostCreatedSkipsWhenNoSubscribers(): void
    {
        $thread = $this->makeThread();
        $this->threads->method('findById')->willReturn($thread);
        $this->subscriptions->method('findByThread')->willReturn([]);

        $this->logger->expects(self::never())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onPostCreated(new PostCreated('p-1', 'thread-1', 'author-1'));
    }

    #[Test]
    public function onPostCreatedUsesAuthorIdWhenDisplayNameEmpty(): void
    {
        $thread = $this->makeThread();
        $this->threads->method('findById')->willReturn($thread);
        $this->subscriptions->method('findByThread')->willReturn([
            $this->makeSubscription('subscriber-1'),
        ]);

        $this->logger->expects(self::once())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onPostCreated(new PostCreated('p-1', 'thread-1', 'author-2', authorDisplayName: ''));
    }

    // --- onVoteCast() ---

    #[Test]
    public function onVoteCastNotifiesOnPostUpvote(): void
    {
        $post = new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'post-author',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            isSolution: false,
            voteScore: 0,
            editCount: 0,
            editedBy: null,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
        );
        $thread = $this->makeThread();

        $this->posts->method('findById')->willReturn($post);
        $this->threads->method('findById')->willReturn($thread);

        $this->logger->expects(self::once())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onVoteCast(new VoteCast(
            voteId: 'v-1',
            targetType: 'post',
            targetId: 'post-1',
            voterId: 'voter-1',
            direction: VoteDirection::Up,
            targetAuthorId: 'post-author',
        ));
    }

    #[Test]
    public function onVoteCastIgnoresDownvotes(): void
    {
        $this->logger->expects(self::never())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onVoteCast(new VoteCast(
            voteId: 'v-1',
            targetType: 'post',
            targetId: 'post-1',
            voterId: 'voter-1',
            direction: VoteDirection::Down,
            targetAuthorId: 'post-author',
        ));
    }

    #[Test]
    public function onVoteCastIgnoresThreadVotes(): void
    {
        $this->logger->expects(self::never())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onVoteCast(new VoteCast(
            voteId: 'v-1',
            targetType: 'thread',
            targetId: 'thread-1',
            voterId: 'voter-1',
            direction: VoteDirection::Up,
            targetAuthorId: 'thread-author',
        ));
    }

    #[Test]
    public function onVoteCastDoesNotNotifySelfVote(): void
    {
        $post = new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'voter-1',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            isSolution: false,
            voteScore: 0,
            editCount: 0,
            editedBy: null,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
        );
        $thread = $this->makeThread();
        $this->posts->method('findById')->willReturn($post);
        $this->threads->method('findById')->willReturn($thread);

        $this->logger->expects(self::never())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onVoteCast(new VoteCast(
            voteId: 'v-1',
            targetType: 'post',
            targetId: 'post-1',
            voterId: 'voter-1',
            direction: VoteDirection::Up,
            targetAuthorId: 'voter-1',
        ));
    }

    // --- onPostAcceptedAsSolution() ---

    #[Test]
    public function onPostAcceptedAsSolutionNotifiesPostAuthor(): void
    {
        $thread = $this->makeThread();
        $this->threads->method('findById')->willReturn($thread);

        $this->logger->expects(self::once())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onPostAcceptedAsSolution(new PostAcceptedAsSolution(
            postId: 'post-1',
            threadId: 'thread-1',
            postAuthorId: 'helper-user',
            acceptedBy: 'thread-author',
        ));
    }

    #[Test]
    public function onPostAcceptedAsSolutionSkipsWhenThreadNotFound(): void
    {
        $this->threads->method('findById')->willReturn(null);
        $this->logger->expects(self::never())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onPostAcceptedAsSolution(new PostAcceptedAsSolution(
            postId: 'post-1',
            threadId: 'missing-thread',
            postAuthorId: 'helper',
            acceptedBy: 'author',
        ));
    }

    #[Test]
    public function onPostAcceptedAsSolutionSkipsSelfAcceptance(): void
    {
        $thread = $this->makeThread();
        $this->threads->method('findById')->willReturn($thread);

        $this->logger->expects(self::never())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onPostAcceptedAsSolution(new PostAcceptedAsSolution(
            postId: 'post-1',
            threadId: 'thread-1',
            postAuthorId: 'same-user',
            acceptedBy: 'same-user',
        ));
    }

    // --- onReportSubmitted() ---

    #[Test]
    public function onReportSubmittedLogsReportDetails(): void
    {
        $this->logger->expects(self::once())->method('info')->with(
            'Forum report submitted',
            self::callback(static fn(array $ctx): bool => $ctx['target_type'] === 'post' && $ctx['reporter_id'] === 'reporter-1'),
        );

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onReportSubmitted(new ReportSubmitted(
            reportId: 'r-1',
            targetType: 'post',
            targetId: 'post-1',
            reporterId: 'reporter-1',
            reason: 'Spam content',
        ));
    }

    // --- findThreadForTarget (via onVoteCast) ---

    #[Test]
    public function onVoteCastWithPostTargetLooksUpPostThenThread(): void
    {
        $post = new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'post-author',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            isSolution: false,
            voteScore: 0,
            editCount: 0,
            editedBy: null,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
        );

        $thread = $this->makeThread();

        $this->posts->method('findById')->willReturn($post);
        $this->threads->method('findById')->willReturn($thread);

        $this->logger->expects(self::once())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onVoteCast(new VoteCast(
            voteId: 'v-1',
            targetType: 'post',
            targetId: 'post-1',
            voterId: 'voter-1',
            direction: VoteDirection::Up,
            targetAuthorId: 'post-author',
        ));
    }

    #[Test]
    public function onVoteCastSkipsWhenPostNotFound(): void
    {
        $this->posts->method('findById')->willReturn(null);

        $this->logger->expects(self::never())->method('info');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->onVoteCast(new VoteCast(
            voteId: 'v-1',
            targetType: 'post',
            targetId: 'missing-post',
            voterId: 'voter-1',
            direction: VoteDirection::Up,
            targetAuthorId: 'author-1',
        ));
    }

    #[Test]
    public function dispatcherWorksWithoutPostRepository(): void
    {
        $dispatcher = new ForumNotificationDispatcher(
            threadRepository: $this->threads,
            subscriptionRepository: $this->subscriptions,
            postRepository: null,
            logger: $this->logger,
        );

        $this->logger->expects(self::never())->method('info');

        $dispatcher->onVoteCast(new VoteCast(
            voteId: 'v-1',
            targetType: 'post',
            targetId: 'post-1',
            voterId: 'voter-1',
            direction: VoteDirection::Up,
            targetAuthorId: 'author-1',
        ));
    }
}
