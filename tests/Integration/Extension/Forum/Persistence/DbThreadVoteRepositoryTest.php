<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Internal\Persistence\DbThreadVoteRepository;
use Pulsar\Extension\Forum\Vote\ThreadVote;

#[CoversClass(DbThreadVoteRepository::class)]
final class DbThreadVoteRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbThreadVoteRepository $repository;

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
            CREATE TABLE IF NOT EXISTS forum_thread_votes (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                thread_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                value INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_thread_vote_user ON forum_thread_votes (thread_id, user_id)
            SQL);

        $this->repository = new DbThreadVoteRepository($this->connection, null);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $vote = ThreadVote::cast(
            id: 'tv-001',
            userId: 'user-001',
            threadId: 'thread-001',
            value: VoteDirection::Up,
        );
        $this->repository->save($vote);

        $found = $this->repository->findById('tv-001');

        self::assertNotNull($found);
        self::assertSame('tv-001', $found->id);
        self::assertSame('user-001', $found->userId);
        self::assertSame('thread-001', $found->threadId);
        self::assertSame(VoteDirection::Up, $found->value);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findByUserAndThread(): void
    {
        $vote = ThreadVote::cast('tv-u', 'user-a', 'thread-a', VoteDirection::Down);
        $this->repository->save($vote);

        $found = $this->repository->findByUserAndThread('user-a', 'thread-a');

        self::assertNotNull($found);
        self::assertSame(VoteDirection::Down, $found->value);
    }

    #[Test]
    public function findByUserAndThreadReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByUserAndThread('user-x', 'thread-x'));
    }

    #[Test]
    public function scoreForThreadAggregatesVotes(): void
    {
        $this->repository->save(ThreadVote::cast('tv1', 'u1', 'thread-s', VoteDirection::Up));
        $this->repository->save(ThreadVote::cast('tv2', 'u2', 'thread-s', VoteDirection::Up));
        $this->repository->save(ThreadVote::cast('tv3', 'u3', 'thread-s', VoteDirection::Down));
        $this->repository->save(ThreadVote::cast('tv4', 'u4', 'thread-s', VoteDirection::Up));

        $score = $this->repository->scoreForThread('thread-s');

        self::assertSame(2, $score);
    }

    #[Test]
    public function scoreForThreadReturnsZeroWithNoVotes(): void
    {
        self::assertSame(0, $this->repository->scoreForThread('no-votes'));
    }

    #[Test]
    public function saveUpdatesExistingVote(): void
    {
        $vote = ThreadVote::cast('tv-flip', 'user-f', 'thread-f', VoteDirection::Up);
        $this->repository->save($vote);

        $flipped = new ThreadVote(
            id: 'tv-flip',
            tenantId: null,
            userId: 'user-f',
            threadId: 'thread-f',
            value: VoteDirection::Down,
            createdAt: $vote->createdAt,
        );
        $this->repository->save($flipped);

        $found = $this->repository->findById('tv-flip');
        self::assertNotNull($found);
        self::assertSame(VoteDirection::Down, $found->value);
    }

    #[Test]
    public function deleteRemovesVote(): void
    {
        $vote = ThreadVote::cast('tv-del', 'user-d', 'thread-d', VoteDirection::Up);
        $this->repository->save($vote);

        $this->repository->delete($vote);

        self::assertNull($this->repository->findById('tv-del'));
    }
}
