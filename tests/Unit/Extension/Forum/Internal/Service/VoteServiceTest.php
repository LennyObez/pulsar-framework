<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Event\VoteCast;
use Pulsar\Extension\Forum\Event\VoteRemoved;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\VoteService;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Extension\Forum\Vote\PostVote;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;
use Pulsar\Extension\Forum\Vote\ThreadVote;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;

#[CoversClass(VoteService::class)]
final class VoteServiceTest extends TestCase
{
    private ThreadVoteRepositoryInterface&Stub $threadVotes;
    private PostVoteRepositoryInterface&Stub $postVotes;
    private ThreadRepositoryInterface&Stub $threads;
    private PostRepositoryInterface&Stub $posts;
    private ForumProfileRepositoryInterface&Stub $profiles;
    private ReputationServiceInterface&Stub $reputationService;
    private BadgeServiceInterface&Stub $badgeService;
    private EventDispatcherInterface&Stub $events;
    private ForumConfig $config;

    protected function setUp(): void
    {
        $this->threadVotes = $this->createStub(ThreadVoteRepositoryInterface::class);
        $this->postVotes = $this->createStub(PostVoteRepositoryInterface::class);
        $this->threads = $this->createStub(ThreadRepositoryInterface::class);
        $this->posts = $this->createStub(PostRepositoryInterface::class);
        $this->profiles = $this->createStub(ForumProfileRepositoryInterface::class);

        $dummyProfile = ForumProfile::create('dummy-id', 'dummy-user');
        $this->reputationService = $this->createStub(ReputationServiceInterface::class);
        $this->reputationService->method('addReputation')->willReturn($dummyProfile);

        $this->badgeService = $this->createStub(BadgeServiceInterface::class);
        $this->events = $this->createStub(EventDispatcherInterface::class);
        $this->config = new ForumConfig();
    }

    private function makeService(
        ?ThreadVoteRepositoryInterface $threadVotes = null,
        ?PostVoteRepositoryInterface $postVotes = null,
        ?ThreadRepositoryInterface $threads = null,
        ?PostRepositoryInterface $posts = null,
        ?ForumProfileRepositoryInterface $profiles = null,
        ?EventDispatcherInterface $events = null,
    ): VoteService {
        return new VoteService(
            threadVotes: $threadVotes ?? $this->threadVotes,
            postVotes: $postVotes ?? $this->postVotes,
            threads: $threads ?? $this->threads,
            posts: $posts ?? $this->posts,
            profiles: $profiles ?? $this->profiles,
            reputationService: $this->reputationService,
            badgeService: $this->badgeService,
            events: $events ?? $this->events,
            config: $this->config,
        );
    }

    private function makeThread(string $id = 'thread-1', string $authorId = 'author-1'): Thread
    {
        return Thread::create(
            id: $id,
            categoryId: 'cat-1',
            authorId: $authorId,
            title: 'Thread',
            slug: 'thread',
            type: ThreadType::Discussion,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );
    }

    private function makePost(string $id = 'post-1', string $authorId = 'author-1', int $voteScore = 0): Post
    {
        return new Post(
            id: $id,
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: $authorId,
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            isSolution: false,
            voteScore: $voteScore,
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
    }

    private function makeProfileWithReputation(int $reputation): ForumProfile
    {
        return new ForumProfile(
            id: 'profile-1',
            tenantId: null,
            userId: 'voter-1',
            reputationScore: $reputation,
            postCount: 0,
            threadCount: 0,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function castThreadVoteUpvoteSucceeds(): void
    {
        $thread = $this->makeThread(authorId: 'thread-author');
        $threadVotes = $this->createMock(ThreadVoteRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threadVotes->method('findByUserAndThread')->willReturn(null);
        $this->threads->method('findById')->willReturn($thread);
        $threadVotes->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(VoteCast::class));

        $service = $this->makeService(threadVotes: $threadVotes, events: $events);

        $vote = $service->castThreadVote('voter-1', 'thread-1', VoteDirection::Up);

        self::assertSame('voter-1', $vote->userId);
        self::assertSame('thread-1', $vote->threadId);
        self::assertSame(VoteDirection::Up, $vote->value);
    }

    #[Test]
    public function castThreadVoteThrowsOnDuplicateVote(): void
    {
        $existingVote = ThreadVote::cast('v-1', 'voter-1', 'thread-1', VoteDirection::Up);
        $this->threadVotes->method('findByUserAndThread')->willReturn($existingVote);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('already voted');

        $service->castThreadVote('voter-1', 'thread-1', VoteDirection::Up);
    }

    #[Test]
    public function castThreadVoteThrowsWhenThreadNotFound(): void
    {
        $this->threadVotes->method('findByUserAndThread')->willReturn(null);
        $this->threads->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Thread not found');

        $service->castThreadVote('voter-1', 'thread-1', VoteDirection::Up);
    }

    #[Test]
    public function castThreadVoteThrowsOnSelfVote(): void
    {
        $thread = $this->makeThread(authorId: 'voter-1');
        $this->threadVotes->method('findByUserAndThread')->willReturn(null);
        $this->threads->method('findById')->willReturn($thread);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('cannot vote on your own');

        $service->castThreadVote('voter-1', 'thread-1', VoteDirection::Up);
    }

    #[Test]
    public function castThreadVoteDownvoteRequiresMinimumReputation(): void
    {
        $thread = $this->makeThread(authorId: 'thread-author');
        $lowRepProfile = $this->makeProfileWithReputation(10);

        $this->threadVotes->method('findByUserAndThread')->willReturn(null);
        $this->threads->method('findById')->willReturn($thread);
        $this->profiles->method('findByUser')->willReturn($lowRepProfile);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Insufficient reputation');

        $service->castThreadVote('voter-1', 'thread-1', VoteDirection::Down);
    }

    #[Test]
    public function castThreadVoteDownvoteSucceedsWithSufficientReputation(): void
    {
        $thread = $this->makeThread(authorId: 'thread-author');
        $highRepProfile = $this->makeProfileWithReputation(100);

        $threadVotes = $this->createMock(ThreadVoteRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threadVotes->method('findByUserAndThread')->willReturn(null);
        $this->threads->method('findById')->willReturn($thread);
        $this->profiles->method('findByUser')->willReturn($highRepProfile);
        $threadVotes->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch');

        $service = $this->makeService(threadVotes: $threadVotes, events: $events);

        $vote = $service->castThreadVote('voter-1', 'thread-1', VoteDirection::Down);

        self::assertSame(VoteDirection::Down, $vote->value);
    }

    #[Test]
    public function castPostVoteUpvoteSucceeds(): void
    {
        $post = $this->makePost(authorId: 'post-author');
        $postVotes = $this->createMock(PostVoteRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $postVotes->method('findByUserAndPost')->willReturn(null);
        $this->posts->method('findById')->willReturn($post);
        $postVotes->expects(self::once())->method('save');
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(VoteCast::class));

        $service = $this->makeService(postVotes: $postVotes, events: $events);

        $vote = $service->castPostVote('voter-1', 'post-1', VoteDirection::Up);

        self::assertSame('voter-1', $vote->userId);
        self::assertSame(VoteDirection::Up, $vote->value);
    }

    #[Test]
    public function castPostVoteThrowsOnDuplicate(): void
    {
        $existing = PostVote::cast('v-1', 'voter-1', 'post-1', VoteDirection::Up);
        $this->postVotes->method('findByUserAndPost')->willReturn($existing);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('already voted');

        $service->castPostVote('voter-1', 'post-1', VoteDirection::Up);
    }

    #[Test]
    public function castPostVoteThrowsWhenPostNotFound(): void
    {
        $this->postVotes->method('findByUserAndPost')->willReturn(null);
        $this->posts->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Post not found');

        $service->castPostVote('voter-1', 'post-1', VoteDirection::Up);
    }

    #[Test]
    public function castPostVoteThrowsOnSelfVote(): void
    {
        $post = $this->makePost(authorId: 'voter-1');
        $this->postVotes->method('findByUserAndPost')->willReturn(null);
        $this->posts->method('findById')->willReturn($post);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('cannot vote on your own');

        $service->castPostVote('voter-1', 'post-1', VoteDirection::Up);
    }

    #[Test]
    public function castPostVoteDownvoteRequiresMinimumReputation(): void
    {
        $post = $this->makePost(authorId: 'post-author');
        $this->postVotes->method('findByUserAndPost')->willReturn(null);
        $this->posts->method('findById')->willReturn($post);
        $this->profiles->method('findByUser')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Insufficient reputation');

        $service->castPostVote('voter-1', 'post-1', VoteDirection::Down);
    }

    #[Test]
    public function removeThreadVoteReversesScoreAndReputation(): void
    {
        $vote = ThreadVote::cast('v-1', 'voter-1', 'thread-1', VoteDirection::Up);
        $thread = $this->makeThread(authorId: 'thread-author');

        $threadVotes = $this->createMock(ThreadVoteRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $threadVotes->method('findByUserAndThread')->willReturn($vote);
        $this->threads->method('findById')->willReturn($thread);
        $threadVotes->expects(self::once())->method('delete')->with($vote);
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(VoteRemoved::class));

        $service = $this->makeService(threadVotes: $threadVotes, events: $events);

        $service->removeThreadVote('voter-1', 'thread-1');
    }

    #[Test]
    public function removeThreadVoteThrowsWhenVoteNotFound(): void
    {
        $this->threadVotes->method('findByUserAndThread')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('ThreadVote not found');

        $service->removeThreadVote('voter-1', 'thread-1');
    }

    #[Test]
    public function removeThreadVoteThrowsWhenThreadNotFound(): void
    {
        $vote = ThreadVote::cast('v-1', 'voter-1', 'thread-1', VoteDirection::Up);
        $this->threadVotes->method('findByUserAndThread')->willReturn($vote);
        $this->threads->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Thread not found');

        $service->removeThreadVote('voter-1', 'thread-1');
    }

    #[Test]
    public function removePostVoteReversesScoreAndReputation(): void
    {
        $vote = PostVote::cast('v-1', 'voter-1', 'post-1', VoteDirection::Down);
        $post = $this->makePost(authorId: 'post-author');

        $postVotes = $this->createMock(PostVoteRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $postVotes->method('findByUserAndPost')->willReturn($vote);
        $this->posts->method('findById')->willReturn($post);
        $postVotes->expects(self::once())->method('delete')->with($vote);
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(VoteRemoved::class));

        $service = $this->makeService(postVotes: $postVotes, events: $events);

        $service->removePostVote('voter-1', 'post-1');
    }

    #[Test]
    public function removePostVoteThrowsWhenVoteNotFound(): void
    {
        $this->postVotes->method('findByUserAndPost')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('PostVote not found');

        $service->removePostVote('voter-1', 'post-1');
    }

    #[Test]
    public function removePostVoteThrowsWhenPostNotFound(): void
    {
        $vote = PostVote::cast('v-1', 'voter-1', 'post-1', VoteDirection::Up);
        $this->postVotes->method('findByUserAndPost')->willReturn($vote);
        $this->posts->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Post not found');

        $service->removePostVote('voter-1', 'post-1');
    }
}
