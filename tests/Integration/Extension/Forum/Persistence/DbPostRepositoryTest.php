<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Persistence\DbPostRepository;
use Pulsar\Extension\Forum\Post\Post;

#[CoversClass(DbPostRepository::class)]
final class DbPostRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbPostRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->createSchema();
        $this->insertCategory('cat-001', 'general');
        $this->insertThread('thread-001', 'cat-001');

        $this->repository = new DbPostRepository($this->connection, null);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $post = Post::create(
            id: 'post-001',
            threadId: 'thread-001',
            authorId: 'user-001',
            body: '**Hello**',
            bodyHtml: '<b>Hello</b>',
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
        $this->repository->save($post);

        $found = $this->repository->findById('post-001');

        self::assertNotNull($found);
        self::assertSame('post-001', $found->id);
        self::assertSame('thread-001', $found->threadId);
        self::assertSame('user-001', $found->authorId);
        self::assertSame('**Hello**', $found->body);
        self::assertSame('<b>Hello</b>', $found->bodyHtml);
        self::assertFalse($found->isSolution);
        self::assertSame(0, $found->voteScore);
        self::assertSame(0, $found->editCount);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findByIdExcludesSoftDeletedPosts(): void
    {
        $post = Post::create(
            id: 'post-del',
            threadId: 'thread-001',
            authorId: 'user-001',
            body: 'to delete',
            bodyHtml: '<p>to delete</p>',
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
        $this->repository->save($post);
        $this->repository->delete($post);

        self::assertNull($this->repository->findById('post-del'));
    }

    #[Test]
    public function findByThreadReturnsPaginated(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $post = Post::create(
                id: "post-t-{$i}",
                threadId: 'thread-001',
                authorId: 'user-001',
                body: "Body {$i}",
                bodyHtml: "<p>Body {$i}</p>",
                ipHash: 'hash-ip',
                userAgentHash: 'hash-ua',
            );
            $this->repository->save($post);
        }

        $result = $this->repository->findByThread('thread-001', page: 1, perPage: 3);

        self::assertSame(5, $result->total);
        self::assertCount(3, $result->items);
        self::assertTrue($result->hasMore);
    }

    #[Test]
    public function findByAuthorReturnsPaginated(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $post = Post::create(
                id: "post-a-{$i}",
                threadId: 'thread-001',
                authorId: 'user-specific',
                body: "Body {$i}",
                bodyHtml: "<p>Body {$i}</p>",
                ipHash: 'hash-ip',
                userAgentHash: 'hash-ua',
            );
            $this->repository->save($post);
        }

        $result = $this->repository->findByAuthor('user-specific');

        self::assertSame(3, $result->total);
        self::assertCount(3, $result->items);
    }

    #[Test]
    public function countByThreadReturnsCorrectCount(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $post = Post::create(
                id: "post-c-{$i}",
                threadId: 'thread-001',
                authorId: 'user-001',
                body: "Body {$i}",
                bodyHtml: "<p>Body {$i}</p>",
                ipHash: 'hash-ip',
                userAgentHash: 'hash-ua',
            );
            $this->repository->save($post);
        }

        self::assertSame(4, $this->repository->countByThread('thread-001'));
    }

    #[Test]
    public function saveUpdatesExistingPost(): void
    {
        $post = Post::create(
            id: 'post-upd',
            threadId: 'thread-001',
            authorId: 'user-001',
            body: 'Original',
            bodyHtml: '<p>Original</p>',
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
            editWindowMinutes: 60,
        );
        $this->repository->save($post);

        $edited = new Post(
            id: 'post-upd',
            tenantId: null,
            threadId: 'thread-001',
            parentId: null,
            authorId: 'user-001',
            body: 'Edited',
            bodyHtml: '<p>Edited</p>',
            isSolution: false,
            voteScore: 0,
            editCount: 1,
            editedBy: 'user-001',
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
            editedAt: new DateTimeImmutable(),
            editWindowExpiresAt: $post->editWindowExpiresAt,
            createdAt: $post->createdAt,
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
            version: 1,
        );
        $this->repository->save($edited);

        $found = $this->repository->findById('post-upd');
        self::assertNotNull($found);
        self::assertSame('Edited', $found->body);
        self::assertSame(1, $found->editCount);
        self::assertSame(2, $found->version);
    }

    #[Test]
    public function concurrencyConflictOnSave(): void
    {
        $post = Post::create(
            id: 'post-cc',
            threadId: 'thread-001',
            authorId: 'user-001',
            body: 'Concurrent',
            bodyHtml: '<p>Concurrent</p>',
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
        $this->repository->save($post);

        $post1 = $this->repository->findById('post-cc');
        self::assertNotNull($post1);
        $updated = new Post(
            id: 'post-cc',
            tenantId: null,
            threadId: 'thread-001',
            parentId: null,
            authorId: 'user-001',
            body: 'Edit A',
            bodyHtml: '<p>Edit A</p>',
            isSolution: false,
            voteScore: 0,
            editCount: 1,
            editedBy: 'user-001',
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
            editedAt: new DateTimeImmutable(),
            editWindowExpiresAt: null,
            createdAt: $post1->createdAt,
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
            version: $post1->version,
        );
        $this->repository->save($updated);

        $stale = new Post(
            id: 'post-cc',
            tenantId: null,
            threadId: 'thread-001',
            parentId: null,
            authorId: 'user-001',
            body: 'Edit B',
            bodyHtml: '<p>Edit B</p>',
            isSolution: false,
            voteScore: 0,
            editCount: 1,
            editedBy: 'user-002',
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
            editedAt: new DateTimeImmutable(),
            editWindowExpiresAt: null,
            createdAt: $post1->createdAt,
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
            version: $post1->version,
        );

        $this->expectException(ForumException::class);
        $this->repository->save($stale);
    }

    #[Test]
    public function incrementVoteScore(): void
    {
        $post = Post::create(
            id: 'post-vs',
            threadId: 'thread-001',
            authorId: 'user-001',
            body: 'Vote me',
            bodyHtml: '<p>Vote me</p>',
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
        $this->repository->save($post);

        $this->repository->incrementVoteScore('post-vs', 3);

        $found = $this->repository->findById('post-vs');
        self::assertNotNull($found);
        self::assertSame(3, $found->voteScore);

        $this->repository->incrementVoteScore('post-vs', -1);

        $found = $this->repository->findById('post-vs');
        self::assertNotNull($found);
        self::assertSame(2, $found->voteScore);
    }

    #[Test]
    public function softDeleteSetsDeletedAt(): void
    {
        $post = Post::create(
            id: 'post-sd',
            threadId: 'thread-001',
            authorId: 'user-001',
            body: 'Soft delete me',
            bodyHtml: '<p>Soft delete me</p>',
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
        $this->repository->save($post);

        $this->repository->delete($post);

        $raw = $this->connection->query(
            'SELECT deleted_at FROM forum_posts WHERE id = :id',
            ['id' => 'post-sd'],
        );
        self::assertNotNull($raw->first()?->getNullableString('deleted_at'));
    }

    private function createSchema(): void
    {
        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_categories (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                slug VARCHAR(200) NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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
            CREATE TABLE IF NOT EXISTS forum_posts (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                thread_id VARCHAR(36) NOT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                author_id VARCHAR(36) NOT NULL,
                body TEXT NOT NULL,
                body_html TEXT NOT NULL,
                is_solution INTEGER NOT NULL DEFAULT 0,
                vote_score INTEGER NOT NULL DEFAULT 0,
                edit_count INTEGER NOT NULL DEFAULT 0,
                edited_by VARCHAR(36) DEFAULT NULL,
                ip_hash VARCHAR(64) NOT NULL,
                user_agent_hash VARCHAR(64) NOT NULL,
                edited_at TEXT DEFAULT NULL,
                edit_window_expires_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at TEXT DEFAULT NULL,
                version INTEGER NOT NULL DEFAULT 1
            )
            SQL);
    }

    private function insertCategory(string $id, string $slug): void
    {
        $now = (new DateTimeImmutable())->format('c');
        $this->connection->execute(
            'INSERT INTO forum_categories (id, slug, created_at, updated_at) VALUES (:id, :slug, :now, :now)',
            ['id' => $id, 'slug' => $slug, 'now' => $now],
        );
    }

    private function insertThread(string $id, string $categoryId): void
    {
        $now = (new DateTimeImmutable())->format('c');
        $this->connection->execute(
            'INSERT INTO forum_threads (id, category_id, author_id, title, slug, ip_hash, user_agent_hash, created_at, updated_at) VALUES (:id, :cat, :author, :title, :slug, :ip, :ua, :now, :now)',
            [
                'id' => $id, 'cat' => $categoryId, 'author' => 'user-001',
                'title' => 'Test', 'slug' => 'test', 'ip' => 'h', 'ua' => 'h', 'now' => $now,
            ],
        );
    }
}
