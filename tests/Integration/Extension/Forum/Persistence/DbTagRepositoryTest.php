<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Forum\Internal\Persistence\DbTagRepository;
use Pulsar\Extension\Forum\Tag\Tag;

#[CoversClass(DbTagRepository::class)]
final class DbTagRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbTagRepository $repository;

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
            CREATE UNIQUE INDEX IF NOT EXISTS uq_tag_slug ON forum_tags (slug)
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_thread_tags (
                tag_id VARCHAR(36) NOT NULL,
                thread_id VARCHAR(36) NOT NULL,
                PRIMARY KEY (tag_id, thread_id)
            )
            SQL);

        $this->repository = new DbTagRepository($this->connection);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $tag = Tag::create(id: 'tag-001', slug: 'php', name: 'PHP');
        $this->repository->save($tag);

        $found = $this->repository->findById('tag-001');

        self::assertNotNull($found);
        self::assertSame('tag-001', $found->id);
        self::assertSame('php', $found->slug);
        self::assertSame('PHP', $found->name);
        self::assertSame(0, $found->usageCount);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findBySlug(): void
    {
        $tag = Tag::create(id: 'tag-002', slug: 'laravel', name: 'Laravel');
        $this->repository->save($tag);

        $found = $this->repository->findBySlug('laravel');

        self::assertNotNull($found);
        self::assertSame('tag-002', $found->id);
    }

    #[Test]
    public function findBySlugReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findBySlug('nonexistent'));
    }

    #[Test]
    public function findAllReturnsAllTagsOrderedByUsage(): void
    {
        $tag1 = new Tag(id: 'tag-a', slug: 'a', name: 'A', description: '', usageCount: 5);
        $tag2 = new Tag(id: 'tag-b', slug: 'b', name: 'B', description: '', usageCount: 20);
        $tag3 = new Tag(id: 'tag-c', slug: 'c', name: 'C', description: '', usageCount: 10);

        $this->repository->save($tag1);
        $this->repository->save($tag2);
        $this->repository->save($tag3);

        $all = $this->repository->findAll();

        self::assertCount(3, $all);
        self::assertSame('tag-b', $all[0]->id);
        self::assertSame('tag-c', $all[1]->id);
        self::assertSame('tag-a', $all[2]->id);
    }

    #[Test]
    public function attachAndDetachToThread(): void
    {
        $this->insertCategory('cat-001', 'general');
        $this->insertThread('thread-001', 'cat-001');

        $tag = Tag::create(id: 'tag-t', slug: 'test', name: 'Test');
        $this->repository->save($tag);

        $this->repository->attachToThread('tag-t', 'thread-001');

        $tags = $this->repository->findByThread('thread-001');
        self::assertCount(1, $tags);
        self::assertSame('tag-t', $tags[0]->id);

        $this->repository->detachFromThread('tag-t', 'thread-001');

        $tags = $this->repository->findByThread('thread-001');
        self::assertCount(0, $tags);
    }

    #[Test]
    public function findByThreadReturnsTagsSortedByName(): void
    {
        $this->insertCategory('cat-001', 'general');
        $this->insertThread('thread-001', 'cat-001');

        $tagA = Tag::create(id: 'tag-a', slug: 'alpha', name: 'Alpha');
        $tagB = Tag::create(id: 'tag-b', slug: 'beta', name: 'Beta');

        $this->repository->save($tagA);
        $this->repository->save($tagB);

        $this->repository->attachToThread('tag-b', 'thread-001');
        $this->repository->attachToThread('tag-a', 'thread-001');

        $tags = $this->repository->findByThread('thread-001');

        self::assertCount(2, $tags);
        self::assertSame('Alpha', $tags[0]->name);
        self::assertSame('Beta', $tags[1]->name);
    }

    #[Test]
    public function findPopularReturnsLimitedResults(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $tag = new Tag(
                id: "tag-{$i}",
                slug: "slug-{$i}",
                name: "Tag {$i}",
                description: '',
                usageCount: ($i + 1) * 10,
            );
            $this->repository->save($tag);
        }

        $popular = $this->repository->findPopular(3);

        self::assertCount(3, $popular);
        self::assertSame(50, $popular[0]->usageCount);
        self::assertSame(40, $popular[1]->usageCount);
        self::assertSame(30, $popular[2]->usageCount);
    }

    #[Test]
    public function saveUpdatesExistingTag(): void
    {
        $tag = Tag::create(id: 'tag-upd', slug: 'old', name: 'Old');
        $this->repository->save($tag);

        $updated = new Tag(id: 'tag-upd', slug: 'new', name: 'New', description: 'Desc', usageCount: 42);
        $this->repository->save($updated);

        $found = $this->repository->findById('tag-upd');

        self::assertNotNull($found);
        self::assertSame('new', $found->slug);
        self::assertSame('New', $found->name);
        self::assertSame(42, $found->usageCount);
    }

    #[Test]
    public function deleteRemovesTag(): void
    {
        $tag = Tag::create(id: 'tag-del', slug: 'remove-me', name: 'Remove');
        $this->repository->save($tag);

        $this->repository->delete($tag);

        self::assertNull($this->repository->findById('tag-del'));
    }

    #[Test]
    public function attachToThreadIsIdempotent(): void
    {
        $this->insertCategory('cat-001', 'general');
        $this->insertThread('thread-001', 'cat-001');

        $tag = Tag::create(id: 'tag-idem', slug: 'idem', name: 'Idempotent');
        $this->repository->save($tag);

        $this->repository->attachToThread('tag-idem', 'thread-001');
        $this->repository->attachToThread('tag-idem', 'thread-001');

        $tags = $this->repository->findByThread('thread-001');
        self::assertCount(1, $tags);
    }

    private function insertCategory(string $id, string $slug): void
    {
        $now = (new \DateTimeImmutable())->format('c');
        $this->connection->execute(
            'INSERT INTO forum_categories (id, slug, created_at, updated_at) VALUES (:id, :slug, :now, :now)',
            ['id' => $id, 'slug' => $slug, 'now' => $now],
        );
    }

    private function insertThread(string $id, string $categoryId): void
    {
        $now = (new \DateTimeImmutable())->format('c');
        $this->connection->execute(
            'INSERT INTO forum_threads (id, category_id, author_id, title, slug, ip_hash, user_agent_hash, created_at, updated_at) VALUES (:id, :cat, :author, :title, :slug, :ip, :ua, :now, :now)',
            [
                'id' => $id,
                'cat' => $categoryId,
                'author' => 'user-001',
                'title' => 'Test Thread',
                'slug' => 'test-thread',
                'ip' => 'hash123',
                'ua' => 'hash456',
                'now' => $now,
            ],
        );
    }
}
