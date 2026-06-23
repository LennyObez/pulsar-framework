<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Persistence\DbThreadRepository;
use Pulsar\Extension\Forum\Thread\Thread;

#[CoversClass(DbThreadRepository::class)]
final class DbThreadRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbThreadRepository $repository;

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
            CREATE TABLE IF NOT EXISTS forum_categories (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                name VARCHAR(200) NOT NULL DEFAULT '',
                slug VARCHAR(200) NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_locked INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at TEXT DEFAULT NULL
            )
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_threads (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                category_id VARCHAR(36) NOT NULL,
                author_id VARCHAR(36) NOT NULL,
                title VARCHAR(200) NOT NULL,
                slug VARCHAR(250) NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT 'discussion',
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                is_pinned INTEGER NOT NULL DEFAULT 0,
                is_locked INTEGER NOT NULL DEFAULT 0,
                solved_post_id VARCHAR(36) DEFAULT NULL,
                reply_count INTEGER NOT NULL DEFAULT 0,
                view_count INTEGER NOT NULL DEFAULT 0,
                vote_score INTEGER NOT NULL DEFAULT 0,
                last_activity_at TEXT DEFAULT NULL,
                ip_hash VARCHAR(64) NOT NULL,
                user_agent_hash VARCHAR(64) NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at TEXT DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1
            )
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_tags (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                slug VARCHAR(100) NOT NULL,
                name VARCHAR(100) NOT NULL,
                description TEXT DEFAULT NULL,
                usage_count INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_thread_tags (
                tag_id VARCHAR(36) NOT NULL,
                thread_id VARCHAR(36) NOT NULL,
                PRIMARY KEY (tag_id, thread_id)
            )
            SQL);

        $this->insertCategory('cat-001', 'general');

        $this->repository = new DbThreadRepository($this->connection, null);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $thread = Thread::create(
            id: 'thread-001',
            categoryId: 'cat-001',
            authorId: 'user-001',
            title: 'Hello World',
            slug: 'hello-world',
            type: ThreadType::Discussion,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );

        $this->repository->save($thread);

        $found = $this->repository->findById('thread-001');

        self::assertNotNull($found);
        self::assertSame('thread-001', $found->id);
        self::assertSame('Hello World', $found->title);
        self::assertSame('hello-world', $found->slug);
        self::assertSame(ThreadType::Discussion, $found->type);
        self::assertSame(ThreadStatus::Open, $found->status);
        self::assertFalse($found->isPinned);
        self::assertFalse($found->isLocked);
        self::assertSame(0, $found->replyCount);
        self::assertSame(0, $found->viewCount);
        self::assertSame(0, $found->voteScore);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findByIdExcludesSoftDeletedThreads(): void
    {
        $thread = Thread::create(
            id: 'thread-del',
            categoryId: 'cat-001',
            authorId: 'user-001',
            title: 'Deleted Thread',
            slug: 'deleted-thread',
            type: ThreadType::Discussion,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );

        $this->repository->save($thread);
        $this->repository->delete($thread);

        self::assertNull($this->repository->findById('thread-del'));
    }

    #[Test]
    public function saveUpdatesExistingThread(): void
    {
        $thread = Thread::create(
            id: 'thread-upd',
            categoryId: 'cat-001',
            authorId: 'user-001',
            title: 'Original',
            slug: 'original',
            type: ThreadType::Discussion,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );

        $this->repository->save($thread);

        $edited = $thread->editTitle('Updated Title', 'updated-title');
        $this->repository->save($edited);

        $found = $this->repository->findById('thread-upd');

        self::assertNotNull($found);
        self::assertSame('Updated Title', $found->title);
        self::assertSame('updated-title', $found->slug);
        self::assertSame(2, $found->version);
    }

    #[Test]
    public function concurrencyConflictThrowsException(): void
    {
        $thread = Thread::create(
            id: 'thread-cc',
            categoryId: 'cat-001',
            authorId: 'user-001',
            title: 'Concurrent',
            slug: 'concurrent',
            type: ThreadType::Discussion,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
        $this->repository->save($thread);

        $thread1 = $this->repository->findById('thread-cc');
        self::assertNotNull($thread1);
        $this->repository->save($thread1->editTitle('Edit A', 'edit-a'));

        $staleThread = $thread1->editTitle('Edit B', 'edit-b');

        $this->expectException(ForumException::class);
        $this->repository->save($staleThread);
    }

    #[Test]
    public function findByCategoryReturnsPaginatedResults(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $thread = Thread::create(
                id: "thread-cat-{$i}",
                categoryId: 'cat-001',
                authorId: 'user-001',
                title: "Thread {$i}",
                slug: "thread-{$i}",
                type: ThreadType::Discussion,
                ipHash: 'hash-ip',
                userAgentHash: 'hash-ua',
            );
            $this->repository->save($thread);
        }

        $result = $this->repository->findByCategory('cat-001', page: 1, perPage: 3);

        self::assertSame(5, $result->total);
        self::assertCount(3, $result->items);
        self::assertTrue($result->hasMore);
        self::assertSame(1, $result->currentPage);
        self::assertSame(2, $result->lastPage);
    }

    #[Test]
    public function findByCategorySecondPage(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $thread = Thread::create(
                id: "thread-p-{$i}",
                categoryId: 'cat-001',
                authorId: 'user-001',
                title: "Thread {$i}",
                slug: "thread-p-{$i}",
                type: ThreadType::Discussion,
                ipHash: 'hash-ip',
                userAgentHash: 'hash-ua',
            );
            $this->repository->save($thread);
        }

        $result = $this->repository->findByCategory('cat-001', page: 2, perPage: 3);

        self::assertSame(5, $result->total);
        self::assertCount(2, $result->items);
        self::assertFalse($result->hasMore);
        self::assertSame(2, $result->currentPage);
    }

    #[Test]
    public function findByAuthorReturnsPaginated(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $thread = Thread::create(
                id: "thread-auth-{$i}",
                categoryId: 'cat-001',
                authorId: 'user-specific',
                title: "My Thread {$i}",
                slug: "my-thread-{$i}",
                type: ThreadType::Discussion,
                ipHash: 'hash-ip',
                userAgentHash: 'hash-ua',
            );
            $this->repository->save($thread);
        }

        $otherThread = Thread::create(
            id: 'thread-other',
            categoryId: 'cat-001',
            authorId: 'user-other',
            title: 'Other Thread',
            slug: 'other-thread',
            type: ThreadType::Discussion,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
        $this->repository->save($otherThread);

        $result = $this->repository->findByAuthor('user-specific');

        self::assertSame(3, $result->total);
        self::assertCount(3, $result->items);
    }

    #[Test]
    public function findByTagReturnsPaginated(): void
    {
        $thread = Thread::create(
            id: 'thread-tag-1',
            categoryId: 'cat-001',
            authorId: 'user-001',
            title: 'Tagged Thread',
            slug: 'tagged-thread',
            type: ThreadType::Discussion,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
        $this->repository->save($thread);

        $this->connection->execute(
            "INSERT INTO forum_tags (id, slug, name, usage_count) VALUES ('tag-1', 'php', 'PHP', 1)",
        );
        $this->connection->execute(
            "INSERT INTO forum_thread_tags (tag_id, thread_id) VALUES ('tag-1', 'thread-tag-1')",
        );

        $result = $this->repository->findByTag('tag-1');

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
        self::assertSame('thread-tag-1', $result->items[0]->id);
    }

    #[Test]
    public function incrementVoteScoreModifiesScore(): void
    {
        $thread = Thread::create(
            id: 'thread-vote',
            categoryId: 'cat-001',
            authorId: 'user-001',
            title: 'Vote Thread',
            slug: 'vote-thread',
            type: ThreadType::Discussion,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
        $this->repository->save($thread);

        $this->repository->incrementVoteScore('thread-vote', 5);

        $found = $this->repository->findById('thread-vote');
        self::assertNotNull($found);
        self::assertSame(5, $found->voteScore);

        $this->repository->incrementVoteScore('thread-vote', -2);

        $found = $this->repository->findById('thread-vote');
        self::assertNotNull($found);
        self::assertSame(3, $found->voteScore);
    }

    #[Test]
    public function incrementReplyCountModifiesCount(): void
    {
        $thread = Thread::create(
            id: 'thread-reply',
            categoryId: 'cat-001',
            authorId: 'user-001',
            title: 'Reply Thread',
            slug: 'reply-thread',
            type: ThreadType::Discussion,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
        $this->repository->save($thread);

        $this->repository->incrementReplyCount('thread-reply', 4);

        $found = $this->repository->findById('thread-reply');
        self::assertNotNull($found);
        self::assertSame(4, $found->replyCount);

        // Decrementing past zero must floor at 0, never go negative.
        $this->repository->incrementReplyCount('thread-reply', -10);

        $found = $this->repository->findById('thread-reply');
        self::assertNotNull($found);
        self::assertSame(0, $found->replyCount);
    }

    #[Test]
    public function softDeleteSetsDeletedAt(): void
    {
        $thread = Thread::create(
            id: 'thread-sd',
            categoryId: 'cat-001',
            authorId: 'user-001',
            title: 'Soft Delete',
            slug: 'soft-delete',
            type: ThreadType::Discussion,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
        $this->repository->save($thread);

        $this->repository->delete($thread);

        self::assertNull($this->repository->findById('thread-sd'));

        $rawResult = $this->connection->query(
            'SELECT deleted_at FROM forum_threads WHERE id = :id',
            ['id' => 'thread-sd'],
        );
        $row = $rawResult->first();
        self::assertNotNull($row);
        self::assertNotNull($row->getNullableString('deleted_at'));
    }

    private function insertCategory(string $id, string $slug): void
    {
        $now = new DateTimeImmutable()->format('c');
        $this->connection->execute(
            'INSERT INTO forum_categories (id, slug, created_at, updated_at) VALUES (:id, :slug, :now, :now)',
            ['id' => $id, 'slug' => $slug, 'now' => $now],
        );
    }
}
