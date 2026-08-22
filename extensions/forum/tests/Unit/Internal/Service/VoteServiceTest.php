<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\VoteService;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;

#[CoversClass(VoteService::class)]
final class VoteServiceTest extends TestCase
{
    private function makeThread(string $id = 'thread-1', string $authorId = 'author-1'): Thread
    {
        $now = new DateTimeImmutable('2025-06-01 12:00:00');

        return new Thread(
            id: $id,
            tenantId: null,
            categoryId: 'cat-1',
            authorId: $authorId,
            title: 'Test Thread',
            slug: 'test-thread',
            type: ThreadType::Discussion,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 0,
            viewCount: 0,
            voteScore: 0,
            lastActivityAt: $now,
            ipHash: 'abc',
            userAgentHash: 'def',
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }

    private function makePost(string $id = 'post-1', string $authorId = 'author-1'): Post
    {
        $now = new DateTimeImmutable('2025-06-01 12:00:00');

        return new Post(
            id: $id,
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: $authorId,
            body: 'Test body',
            bodyHtml: '<p>Test body</p>',
            isSolution: false,
            voteScore: 0,
            editCount: 0,
            editedBy: null,
            ipHash: 'abc',
            userAgentHash: 'def',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }

    private function makeService(
        ?ThreadVoteRepositoryInterface $threadVotes = null,
        ?PostVoteRepositoryInterface $postVotes = null,
        ?ThreadRepositoryInterface $threads = null,
        ?PostRepositoryInterface $posts = null,
    ): VoteService {
        return new VoteService(
            threadVotes: $threadVotes ?? $this->createStub(ThreadVoteRepositoryInterface::class),
            postVotes: $postVotes ?? $this->createStub(PostVoteRepositoryInterface::class),
            threads: $threads ?? $this->createStub(ThreadRepositoryInterface::class),
            posts: $posts ?? $this->createStub(PostRepositoryInterface::class),
            profiles: $this->createStub(ForumProfileRepositoryInterface::class),
            reputationService: $this->createStub(ReputationServiceInterface::class),
            badgeService: $this->createStub(BadgeServiceInterface::class),
            events: $this->createStub(EventDispatcherInterface::class),
            config: new ForumConfig(),
        );
    }

    #[Test]
    public function castThreadVoteThrowsDuplicateOnUniqueConstraintViolation(): void
    {
        // Arrange: findByUserAndThread returns null (race window),
        // but save() throws DatabaseException (unique constraint at DB level)
        $threadVotes = $this->createStub(ThreadVoteRepositoryInterface::class);
        $threadVotes->method('findByUserAndThread')->willReturn(null);
        $threadVotes->method('save')->willThrowException(
            DatabaseException::uniqueConstraintViolation('uq_thread_vote_user_thread'),
        );

        $threads = $this->createStub(ThreadRepositoryInterface::class);
        $threads->method('findById')->willReturn($this->makeThread(authorId: 'other-user'));

        $service = $this->makeService(threadVotes: $threadVotes, threads: $threads);

        // Act & Assert
        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('already voted');

        $service->castThreadVote('voter-1', 'thread-1', VoteDirection::Up);
    }

    #[Test]
    public function castPostVoteThrowsDuplicateOnUniqueConstraintViolation(): void
    {
        // Arrange: findByUserAndPost returns null (race window),
        // but save() throws DatabaseException (unique constraint at DB level)
        $postVotes = $this->createStub(PostVoteRepositoryInterface::class);
        $postVotes->method('findByUserAndPost')->willReturn(null);
        $postVotes->method('save')->willThrowException(
            DatabaseException::uniqueConstraintViolation('uq_post_vote_user_post'),
        );

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($this->makePost(authorId: 'other-user'));

        $service = $this->makeService(postVotes: $postVotes, posts: $posts);

        // Act & Assert
        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('already voted');

        $service->castPostVote('voter-1', 'post-1', VoteDirection::Up);
    }

    #[Test]
    public function castThreadVoteStillRejectsDuplicateFromApplicationCheck(): void
    {
        // Arrange: findByUserAndThread returns existing vote (normal path)
        $existingVote = \Pulsar\Extension\Forum\Vote\ThreadVote::cast(
            id: 'vote-existing',
            userId: 'voter-1',
            threadId: 'thread-1',
            value: VoteDirection::Up,
        );

        $threadVotes = $this->createStub(ThreadVoteRepositoryInterface::class);
        $threadVotes->method('findByUserAndThread')->willReturn($existingVote);

        $service = $this->makeService(threadVotes: $threadVotes);

        // Act & Assert
        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('already voted');

        $service->castThreadVote('voter-1', 'thread-1', VoteDirection::Up);
    }

    #[Test]
    public function castPostVoteStillRejectsDuplicateFromApplicationCheck(): void
    {
        // Arrange: findByUserAndPost returns existing vote (normal path)
        $existingVote = \Pulsar\Extension\Forum\Vote\PostVote::cast(
            id: 'vote-existing',
            userId: 'voter-1',
            postId: 'post-1',
            value: VoteDirection::Up,
        );

        $postVotes = $this->createStub(PostVoteRepositoryInterface::class);
        $postVotes->method('findByUserAndPost')->willReturn($existingVote);

        $service = $this->makeService(postVotes: $postVotes);

        // Act & Assert
        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('already voted');

        $service->castPostVote('voter-1', 'post-1', VoteDirection::Up);
    }
}
