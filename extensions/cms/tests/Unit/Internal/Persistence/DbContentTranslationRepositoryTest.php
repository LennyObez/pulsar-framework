<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Persistence;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentTranslationRepository;

#[CoversClass(DbContentTranslationRepository::class)]
final class DbContentTranslationRepositoryTest extends TestCase
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

        // Create a minimal cms_content_translations table without GENERATED columns
        // (matching what the code actually queries against: tenant_id, not tenant_key)
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
    public function findByPathReturnsSingleTenantRow(): void
    {
        // Arrange: insert a translation with NULL tenant_id (single-tenant mode)
        $this->insertTranslation(
            id: '00000000-0000-0000-0000-000000000001',
            contentId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            locale: 'en',
            tenantId: null,
            path: 'about',
            title: 'About Us',
            slugSegment: 'about',
        );

        // Act: query with null tenant (single-tenant mode)
        $repo = new DbContentTranslationRepository($this->connection, null);
        $result = $repo->findByPath('en', 'about');

        // Assert
        self::assertNotNull($result, 'findByPath must return the translation for single-tenant (null) rows');
        self::assertSame('About Us', $result->title);
        self::assertSame('about', $result->path);
    }

    #[Test]
    public function findByPathReturnsMultiTenantRow(): void
    {
        // Arrange: insert a translation with a specific tenant_id
        $tenantId = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $this->insertTranslation(
            id: '00000000-0000-0000-0000-000000000002',
            contentId: 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            locale: 'en',
            tenantId: $tenantId,
            path: 'products',
            title: 'Products',
            slugSegment: 'products',
        );

        // Act: query with that specific tenant
        $repo = new DbContentTranslationRepository($this->connection, $tenantId);
        $result = $repo->findByPath('en', 'products');

        // Assert
        self::assertNotNull($result, 'findByPath must return the translation for a specific tenant');
        self::assertSame('Products', $result->title);
    }

    #[Test]
    public function findByPathDoesNotLeakAcrossTenants(): void
    {
        // Arrange: insert for tenant A
        $tenantA = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $tenantB = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

        $this->insertTranslation(
            id: '00000000-0000-0000-0000-000000000003',
            contentId: 'dddddddd-dddd-dddd-dddd-dddddddddddd',
            locale: 'en',
            tenantId: $tenantA,
            path: 'secret',
            title: 'Tenant A Secret',
            slugSegment: 'secret',
        );

        // Act: query with tenant B
        $repo = new DbContentTranslationRepository($this->connection, $tenantB);
        $result = $repo->findByPath('en', 'secret');

        // Assert: tenant B should not see tenant A's content
        self::assertNull($result, 'findByPath must not return content belonging to a different tenant');
    }

    #[Test]
    public function findByPathReturnsNullForNonExistentPath(): void
    {
        $repo = new DbContentTranslationRepository($this->connection, null);
        $result = $repo->findByPath('en', 'nonexistent');

        self::assertNull($result);
    }

    #[Test]
    public function saveAndFindByIdRoundTrip(): void
    {
        // Arrange
        $repo = new DbContentTranslationRepository($this->connection, null);
        $translation = new ContentTranslation(
            id: '11111111-1111-1111-1111-111111111111',
            contentId: 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            locale: 'en',
            title: 'My Page',
            slugSegment: 'my-page',
            path: 'my-page',
            body: '<p>Hello</p>',
            excerpt: 'A page',
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 1,
            bodyPlaintext: 'Hello',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        // Act
        $repo->save($translation);
        $found = $repo->findById('11111111-1111-1111-1111-111111111111');

        // Assert
        self::assertNotNull($found);
        self::assertSame('My Page', $found->title);
        self::assertSame('my-page', $found->path);
        self::assertSame('<p>Hello</p>', $found->body);
        self::assertSame('A page', $found->excerpt);
        self::assertSame(1, $found->readingTimeMinutes);
    }

    #[Test]
    public function findByContentIdReturnsAllTranslations(): void
    {
        // Arrange
        $contentId = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
        $this->insertTranslation(
            id: '00000000-0000-0000-0000-000000000010',
            contentId: $contentId,
            locale: 'en',
            tenantId: null,
            path: 'hello',
            title: 'Hello',
            slugSegment: 'hello',
        );
        $this->insertTranslation(
            id: '00000000-0000-0000-0000-000000000011',
            contentId: $contentId,
            locale: 'fr',
            tenantId: null,
            path: 'bonjour',
            title: 'Bonjour',
            slugSegment: 'bonjour',
        );

        // Act
        $repo = new DbContentTranslationRepository($this->connection, null);
        $translations = $repo->findByContentId($contentId);

        // Assert
        self::assertCount(2, $translations);
        $locales = array_map(static fn(ContentTranslation $t): string => $t->locale, $translations);
        self::assertContains('en', $locales);
        self::assertContains('fr', $locales);
    }

    #[Test]
    public function findByContentAndLocaleReturnsExactMatch(): void
    {
        // Arrange
        $contentId = 'aaaaaaaa-aaaa-aaaa-aaaa-000000000001';
        $this->insertTranslation(
            id: '00000000-0000-0000-0000-000000000020',
            contentId: $contentId,
            locale: 'en',
            tenantId: null,
            path: 'test',
            title: 'English Test',
            slugSegment: 'test',
        );

        // Act
        $repo = new DbContentTranslationRepository($this->connection, null);
        $found = $repo->findByContentAndLocale($contentId, 'en');
        $notFound = $repo->findByContentAndLocale($contentId, 'fr');

        // Assert
        self::assertNotNull($found);
        self::assertSame('English Test', $found->title);
        self::assertNull($notFound);
    }

    #[Test]
    public function deleteRemovesTranslation(): void
    {
        // Arrange
        $id = '00000000-0000-0000-0000-000000000030';
        $this->insertTranslation(
            id: $id,
            contentId: 'bbbbbbbb-aaaa-aaaa-aaaa-000000000001',
            locale: 'en',
            tenantId: null,
            path: 'to-delete',
            title: 'Delete Me',
            slugSegment: 'to-delete',
        );

        $repo = new DbContentTranslationRepository($this->connection, null);
        self::assertNotNull($repo->findById($id));

        // Act
        $repo->delete($id);

        // Assert
        self::assertNull($repo->findById($id));
    }

    private function insertTranslation(
        string $id,
        string $contentId,
        string $locale,
        ?string $tenantId,
        string $path,
        string $title,
        string $slugSegment,
    ): void {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO cms_content_translations
                    (id, content_id, locale, tenant_id, title, slug_segment, path, body, body_plaintext, headings_text, custom_fields_text, taxonomy_terms_text)
                VALUES
                    (:id, :content_id, :locale, :tenant_id, :title, :slug_segment, :path, '', '', '', '', '')
                SQL,
            [
                'id' => $id,
                'content_id' => $contentId,
                'locale' => $locale,
                'tenant_id' => $tenantId,
                'title' => $title,
                'slug_segment' => $slugSegment,
                'path' => $path,
            ],
        );
    }
}
