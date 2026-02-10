<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Internal\Persistence\DbPostVoteRepository;
use Pulsar\Extension\Forum\Vote\PostVote;

#[CoversClass(DbPostVoteRepository::class)]
final class DbPostVoteRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbPostVoteRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_post_votes (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                post_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                value INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_post_vote_user ON forum_post_votes (post_id, user_id)
            SQL);

        $this->repository = new DbPostVoteRepository($this->connection, null);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $vote = PostVote::cast(
            id: 'vote-001',
            userId: 'user-001',
            postId: 'post-001',
            value: VoteDirection::Up,
        );
        $this->repository->save($vote);

        $found = $this->repository->findById('vote-001');

        self::assertNotNull($found);
        self::assertSame('vote-001', $found->id);
        self::assertSame('user-001', $found->userId);
        self::assertSame('post-001', $found->postId);
        self::assertSame(VoteDirection::Up, $found->value);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findByUserAndPost(): void
    {
        $vote = PostVote::cast(
            id: 'vote-up',
            userId: 'user-a',
            postId: 'post-a',
            value: VoteDirection::Down,
        );
        $this->repository->save($vote);

        $found = $this->repository->findByUserAndPost('user-a', 'post-a');

        self::assertNotNull($found);
        self::assertSame(VoteDirection::Down, $found->value);
    }

    #[Test]
    public function findByUserAndPostReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByUserAndPost('user-x', 'post-x'));
    }

    #[Test]
    public function scoreForPostAggregatesVotes(): void
    {
        $this->repository->save(PostVote::cast('v1', 'u1', 'post-score', VoteDirection::Up));
        $this->repository->save(PostVote::cast('v2', 'u2', 'post-score', VoteDirection::Up));
        $this->repository->save(PostVote::cast('v3', 'u3', 'post-score', VoteDirection::Down));

        $score = $this->repository->scoreForPost('post-score');

        self::assertSame(1, $score);
    }

    #[Test]
    public function scoreForPostReturnsZeroWithNoVotes(): void
    {
        self::assertSame(0, $this->repository->scoreForPost('no-votes'));
    }

    #[Test]
    public function saveUpdatesExistingVote(): void
    {
        $vote = PostVote::cast('vote-flip', 'user-f', 'post-f', VoteDirection::Up);
        $this->repository->save($vote);

        $flipped = new PostVote(
            id: 'vote-flip',
            tenantId: null,
            userId: 'user-f',
            postId: 'post-f',
            value: VoteDirection::Down,
            createdAt: $vote->createdAt,
        );
        $this->repository->save($flipped);

        $found = $this->repository->findById('vote-flip');
        self::assertNotNull($found);
        self::assertSame(VoteDirection::Down, $found->value);
    }

    #[Test]
    public function deleteRemovesVote(): void
    {
        $vote = PostVote::cast('vote-del', 'user-d', 'post-d', VoteDirection::Up);
        $this->repository->save($vote);

        $this->repository->delete($vote);

        self::assertNull($this->repository->findById('vote-del'));
    }
}
