<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Persistence;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentRepository;

#[CoversClass(DbContentRepository::class)]
final class DbContentRepositoryTest extends TestCase
{
    private PdoConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: \Pulsar\Database\Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
            options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->connection->execute(<<<'SQL'
            CREATE TABLE cms_contents (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                content_type VARCHAR(100) NOT NULL,
                author_id VARCHAR(36) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                scheduled_publish_at TEXT DEFAULT NULL,
                scheduled_unpublish_at TEXT DEFAULT NULL,
                published_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                deleted_at TEXT DEFAULT NULL,
                template VARCHAR(255) DEFAULT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                comment_policy VARCHAR(20) NOT NULL DEFAULT 'inherit',
                data_classification VARCHAR(20) NOT NULL DEFAULT 'public',
                version INTEGER NOT NULL DEFAULT 1
            )
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE TABLE cms_content_translations (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                content_id VARCHAR(36) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                tenant_id VARCHAR(36) DEFAULT NULL,
                title VARCHAR(500) NOT NULL,
                slug_segment VARCHAR(200) NOT NULL,
                path VARCHAR(2000) NOT NULL,
                body TEXT NOT NULL DEFAULT '',
                excerpt TEXT DEFAULT NULL,
                meta_title VARCHAR(70) DEFAULT NULL,
                meta_description VARCHAR(170) DEFAULT NULL,
                og_image_id VARCHAR(36) DEFAULT NULL,
                robots VARCHAR(200) DEFAULT NULL,
                structured_data_overrides TEXT DEFAULT NULL,
                reading_time_minutes INTEGER DEFAULT NULL,
                body_plaintext TEXT NOT NULL DEFAULT '',
                headings_text TEXT NOT NULL DEFAULT '',
                custom_fields_text TEXT NOT NULL DEFAULT '',
                taxonomy_terms_text TEXT NOT NULL DEFAULT ''
            )
            SQL);
    }

    #[Test]
    public function findByIdReturnsSingleTenantContent(): void
    {
        // Arrange: insert content with NULL tenant_id
        $now = new DateTimeImmutable();
        $this->insertContent(
            id: '11111111-1111-1111-1111-111111111111',
            tenantId: null,
            contentType: 'page',
            authorId: 'aaaa0000-0000-0000-0000-000000000001',
            status: 'draft',
            template: 'default',
            createdAt: $now,
            updatedAt: $now,
        );

        // Act
        $repo = new DbContentRepository($this->connection, null);
        $result = $repo->findById('11111111-1111-1111-1111-111111111111');

        // Assert
        self::assertNotNull($result, 'findById must return content for single-tenant (null) mode');
        self::assertSame('11111111-1111-1111-1111-111111111111', $result->id);
        self::assertSame('default', $result->template);
        self::assertNull($result->tenantId);
    }

    #[Test]
    public function findByIdReturnsMultiTenantContent(): void
    {
        // Arrange
        $tenantId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $now = new DateTimeImmutable();
        $this->insertContent(
            id: '22222222-2222-2222-2222-222222222222',
            tenantId: $tenantId,
            contentType: 'article',
            authorId: 'aaaa0000-0000-0000-0000-000000000002',
            status: 'published',
            template: 'blog',
            createdAt: $now,
            updatedAt: $now,
            publishedAt: $now,
        );

        // Act
        $repo = new DbContentRepository($this->connection, $tenantId);
        $result = $repo->findById('22222222-2222-2222-2222-222222222222');

        // Assert
        self::assertNotNull($result);
        self::assertSame($tenantId, $result->tenantId);
        self::assertSame('blog', $result->template);
    }

    #[Test]
    public function findByIdIsolatesTenants(): void
    {
        // Arrange
        $tenantA = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $tenantB = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $now = new DateTimeImmutable();

        $this->insertContent(
            id: '33333333-3333-3333-3333-333333333333',
            tenantId: $tenantA,
            contentType: 'page',
            authorId: 'aaaa0000-0000-0000-0000-000000000003',
            status: 'draft',
            createdAt: $now,
            updatedAt: $now,
        );

        // Act: tenant B tries to access tenant A's content
        $repo = new DbContentRepository($this->connection, $tenantB);
        $result = $repo->findById('33333333-3333-3333-3333-333333333333');

        // Assert
        self::assertNull($result, 'Content from tenant A must not be visible to tenant B');
    }

    #[Test]
    public function findByPathUsesTenantIdOnTranslationsJoin(): void
    {
        // Arrange
        $now = new DateTimeImmutable();
        $contentId = '44444444-4444-4444-4444-444444444444';

        $this->insertContent(
            id: $contentId,
            tenantId: null,
            contentType: 'page',
            authorId: 'aaaa0000-0000-0000-0000-000000000004',
            status: 'draft',
            createdAt: $now,
            updatedAt: $now,
        );

        $this->insertTranslation(
            id: '55555555-5555-5555-5555-555555555555',
            contentId: $contentId,
            locale: 'en',
            tenantId: null,
            path: 'docs/getting-started',
        );

        // Act
        $repo = new DbContentRepository($this->connection, null);
        $result = $repo->findByPath('en', 'docs/getting-started');

        // Assert
        self::assertNotNull($result, 'findByPath must resolve content via translation tenant_id column');
        self::assertSame($contentId, $result->id);
    }

    #[Test]
    public function findByPathIsolatesTenantsOnTranslationsJoin(): void
    {
        // Arrange: content + translation for tenant A
        $tenantA = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $tenantB = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $now = new DateTimeImmutable();
        $contentId = '66666666-6666-6666-6666-666666666666';

        $this->insertContent(
            id: $contentId,
            tenantId: $tenantA,
            contentType: 'page',
            authorId: 'aaaa0000-0000-0000-0000-000000000005',
            status: 'draft',
            createdAt: $now,
            updatedAt: $now,
        );

        $this->insertTranslation(
            id: '77777777-7777-7777-7777-777777777777',
            contentId: $contentId,
            locale: 'en',
            tenantId: $tenantA,
            path: 'private-page',
        );

        // Act: tenant B queries the same path
        $repo = new DbContentRepository($this->connection, $tenantB);
        $result = $repo->findByPath('en', 'private-page');

        // Assert
        self::assertNull($result, 'findByPath must not leak content across tenants on translation join');
    }

    #[Test]
    public function saveAndFindByIdRoundTrip(): void
    {
        // Arrange
        $content = Content::create(
            id: '88888888-8888-8888-8888-888888888888',
            contentType: ContentType::Page,
            authorId: 'aaaa0000-0000-0000-0000-000000000006',
            tenantId: null,
            template: 'landing-page',
        );

        // Act
        $repo = new DbContentRepository($this->connection, null);
        $repo->save($content);
        $found = $repo->findById('88888888-8888-8888-8888-888888888888');

        // Assert
        self::assertNotNull($found);
        self::assertSame('landing-page', $found->template);
        self::assertSame(ContentType::Page, $found->contentType);
        self::assertSame(PublishingStatus::Draft, $found->status);
    }

    #[Test]
    public function templateIsPersistedOnSave(): void
    {
        // Arrange
        $content = Content::create(
            id: '99999999-9999-9999-9999-999999999999',
            contentType: ContentType::Article,
            authorId: 'aaaa0000-0000-0000-0000-000000000007',
            template: 'blog-post',
        );

        // Act
        $repo = new DbContentRepository($this->connection, null);
        $repo->save($content);

        // Read raw row to verify the template column is actually stored
        $row = $this->connection->query(
            'SELECT template FROM cms_contents WHERE id = :id',
            ['id' => '99999999-9999-9999-9999-999999999999'],
        )->first();

        // Assert
        self::assertNotNull($row);
        self::assertSame('blog-post', $row->getString('template'));
    }

    #[Test]
    public function templateIsUpdatedOnSubsequentSave(): void
    {
        // Arrange
        $content = Content::create(
            id: 'aaaaaaaa-0000-0000-0000-000000000001',
            contentType: ContentType::Page,
            authorId: 'aaaa0000-0000-0000-0000-000000000008',
            template: 'initial-template',
        );

        $repo = new DbContentRepository($this->connection, null);
        $repo->save($content);

        // Act: update with new template (construct new Content directly since clone-with
        // is scope-limited in PHP 8.5 for readonly classes)
        $updated = new Content(
            id: $content->id,
            tenantId: $content->tenantId,
            contentType: $content->contentType,
            authorId: $content->authorId,
            status: $content->status,
            scheduledPublishAt: $content->scheduledPublishAt,
            scheduledUnpublishAt: $content->scheduledUnpublishAt,
            publishedAt: $content->publishedAt,
            createdAt: $content->createdAt,
            updatedAt: new DateTimeImmutable(),
            deletedAt: $content->deletedAt,
            template: 'new-template',
            parentId: $content->parentId,
            sortOrder: $content->sortOrder,
            commentPolicy: $content->commentPolicy,
            dataClassification: $content->dataClassification,
            version: $content->version,
        );
        $repo->save($updated);

        // Assert
        $found = $repo->findById('aaaaaaaa-0000-0000-0000-000000000001');
        self::assertNotNull($found);
        self::assertSame('new-template', $found->template);
    }

    #[Test]
    public function softDeleteExcludesFromFindById(): void
    {
        // Arrange
        $now = new DateTimeImmutable();
        $this->insertContent(
            id: 'bbbbbbbb-0000-0000-0000-000000000001',
            tenantId: null,
            contentType: 'page',
            authorId: 'aaaa0000-0000-0000-0000-000000000009',
            status: 'draft',
            createdAt: $now,
            updatedAt: $now,
            deletedAt: $now,
        );

        // Act
        $repo = new DbContentRepository($this->connection, null);
        $result = $repo->findById('bbbbbbbb-0000-0000-0000-000000000001');

        // Assert
        self::assertNull($result, 'Soft-deleted content must not be returned by findById');
    }

    #[Test]
    public function findPublishedUsesCoalesceOnTranslationTenantId(): void
    {
        // Arrange: published content + translation, single-tenant
        $now = new DateTimeImmutable();
        $contentId = 'cccccccc-0000-0000-0000-000000000001';

        $this->insertContent(
            id: $contentId,
            tenantId: null,
            contentType: 'article',
            authorId: 'aaaa0000-0000-0000-0000-000000000010',
            status: 'published',
            createdAt: $now,
            updatedAt: $now,
            publishedAt: $now,
        );

        $this->insertTranslation(
            id: 'dddddddd-0000-0000-0000-000000000001',
            contentId: $contentId,
            locale: 'en',
            tenantId: null,
            path: 'published-article',
        );

        // Act
        $repo = new DbContentRepository($this->connection, null);
        $result = $repo->findPublished('en');

        // Assert
        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
        self::assertSame($contentId, $result->items[0]->id);
    }

    private function insertContent(
        string $id,
        ?string $tenantId,
        string $contentType,
        string $authorId,
        string $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?string $template = null,
        ?DateTimeImmutable $publishedAt = null,
        ?DateTimeImmutable $deletedAt = null,
    ): void {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO cms_contents
                    (id, tenant_id, content_type, author_id, status, created_at, updated_at, template, published_at, deleted_at, sort_order, comment_policy, data_classification, version)
                VALUES
                    (:id, :tenant_id, :content_type, :author_id, :status, :created_at, :updated_at, :template, :published_at, :deleted_at, 0, 'inherit', 'public', 1)
                SQL,
            [
                'id' => $id,
                'tenant_id' => $tenantId,
                'content_type' => $contentType,
                'author_id' => $authorId,
                'status' => $status,
                'created_at' => $createdAt->format('c'),
                'updated_at' => $updatedAt->format('c'),
                'template' => $template,
                'published_at' => $publishedAt?->format('c'),
                'deleted_at' => $deletedAt?->format('c'),
            ],
        );
    }

    private function insertTranslation(
        string $id,
        string $contentId,
        string $locale,
        ?string $tenantId,
        string $path,
    ): void {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO cms_content_translations
                    (id, content_id, locale, tenant_id, title, slug_segment, path, body, body_plaintext, headings_text, custom_fields_text, taxonomy_terms_text)
                VALUES
                    (:id, :content_id, :locale, :tenant_id, 'Title', 'slug', :path, '', '', '', '', '')
                SQL,
            [
                'id' => $id,
                'content_id' => $contentId,
                'locale' => $locale,
                'tenant_id' => $tenantId,
                'path' => $path,
            ],
        );
    }
}
