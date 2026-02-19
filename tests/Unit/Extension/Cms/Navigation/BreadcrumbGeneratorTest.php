<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Navigation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGenerator;

#[CoversClass(BreadcrumbGenerator::class)]
final class BreadcrumbGeneratorTest extends TestCase
{
    private const string NOW = '2025-06-15T10:00:00+00:00';

    // -- Root content (no ancestors) -----------------------------------------

    #[Test]
    public function test_root_content_returns_single_breadcrumb(): void
    {
        $content = $this->createContent('root-1', parentId: null);
        $translation = $this->createTranslation('root-1', 'en', 'Home', 'home');

        $generator = $this->createGenerator(
            contents: ['root-1' => $content],
            translations: ['root-1' => [$translation]],
        );

        $trail = $generator->generate($content, 'en');

        self::assertCount(1, $trail);
        self::assertSame('Home', $trail[0]->label);
        self::assertSame('/home', $trail[0]->url);
        self::assertTrue($trail[0]->isCurrent);
    }

    // -- Nested content (with ancestors) --------------------------------------

    #[Test]
    public function test_nested_content_returns_root_first_trail(): void
    {
        $root = $this->createContent('root-1', parentId: null);
        $child = $this->createContent('child-1', parentId: 'root-1');

        $generator = $this->createGenerator(
            contents: ['root-1' => $root, 'child-1' => $child],
            translations: [
                'root-1' => [$this->createTranslation('root-1', 'en', 'Home', 'home')],
                'child-1' => [$this->createTranslation('child-1', 'en', 'About', 'home/about')],
            ],
            ancestors: ['child-1' => [$root]],
        );

        $trail = $generator->generate($child, 'en');

        self::assertCount(2, $trail);
        // Root first
        self::assertSame('Home', $trail[0]->label);
        self::assertFalse($trail[0]->isCurrent);
        // Current last
        self::assertSame('About', $trail[1]->label);
        self::assertTrue($trail[1]->isCurrent);
    }

    #[Test]
    public function test_three_level_hierarchy(): void
    {
        $root = $this->createContent('root-1', parentId: null);
        $docs = $this->createContent('docs-1', parentId: 'root-1');
        $tutorial = $this->createContent('tut-1', parentId: 'docs-1');

        $generator = $this->createGenerator(
            contents: ['root-1' => $root, 'docs-1' => $docs, 'tut-1' => $tutorial],
            translations: [
                'root-1' => [$this->createTranslation('root-1', 'en', 'Home', 'home')],
                'docs-1' => [$this->createTranslation('docs-1', 'en', 'Docs', 'home/docs')],
                'tut-1' => [$this->createTranslation('tut-1', 'en', 'Tutorial', 'home/docs/tutorial')],
            ],
            ancestors: ['tut-1' => [$docs, $root]],
        );

        $trail = $generator->generate($tutorial, 'en');

        self::assertCount(3, $trail);
        self::assertSame('Home', $trail[0]->label);
        self::assertSame('Docs', $trail[1]->label);
        self::assertSame('Tutorial', $trail[2]->label);
        self::assertTrue($trail[2]->isCurrent);
    }

    // -- Missing translations -------------------------------------------------

    #[Test]
    public function test_skips_ancestor_without_translation(): void
    {
        $root = $this->createContent('root-1', parentId: null);
        $child = $this->createContent('child-1', parentId: 'root-1');

        // Root has no translation for locale 'en'
        $generator = $this->createGenerator(
            contents: ['root-1' => $root, 'child-1' => $child],
            translations: [
                'child-1' => [$this->createTranslation('child-1', 'en', 'Child', 'child')],
            ],
            ancestors: ['child-1' => [$root]],
        );

        $trail = $generator->generate($child, 'en');

        self::assertCount(1, $trail);
        self::assertSame('Child', $trail[0]->label);
        self::assertTrue($trail[0]->isCurrent);
    }

    // -- Non-default locale with prefix ---------------------------------------

    #[Test]
    public function test_non_default_locale_includes_locale_prefix(): void
    {
        $content = $this->createContent('page-1', parentId: null);
        $translation = $this->createTranslation('page-1', 'fr', 'Accueil', 'accueil');

        $generator = $this->createGenerator(
            contents: ['page-1' => $content],
            translations: ['page-1' => [$translation]],
            defaultLocale: 'en',
        );

        $trail = $generator->generate($content, 'fr');

        self::assertCount(1, $trail);
        self::assertSame('/fr/accueil', $trail[0]->url);
    }

    // -- No ancestors (orphan) ------------------------------------------------

    #[Test]
    public function test_content_with_no_translation_returns_empty(): void
    {
        $content = $this->createContent('orphan-1', parentId: null);

        $generator = $this->createGenerator(
            contents: ['orphan-1' => $content],
            translations: [],
        );

        $trail = $generator->generate($content, 'en');

        self::assertCount(0, $trail);
    }

    // -- Uses batch loading (no N+1) ------------------------------------------

    #[Test]
    public function test_uses_batch_query_for_ancestors_and_translations(): void
    {
        $root = $this->createContent('root-1', parentId: null);
        $child = $this->createContent('child-1', parentId: 'root-1');

        $ancestorCallCount = 0;
        $translationBatchCallCount = 0;

        $contentRepo = $this->createContentRepo(
            ['root-1' => $root, 'child-1' => $child],
            ['child-1' => [$root]],
            $ancestorCallCount,
        );

        $translationRepo = $this->createTranslationRepo(
            [
                'root-1' => [$this->createTranslation('root-1', 'en', 'Home', 'home')],
                'child-1' => [$this->createTranslation('child-1', 'en', 'Child', 'home/child')],
            ],
            $translationBatchCallCount,
        );

        $config = new CmsConfig(defaultLocale: 'en');
        $generator = new BreadcrumbGenerator($contentRepo, $translationRepo, $config);

        $trail = $generator->generate($child, 'en');

        self::assertCount(2, $trail);
        // findAncestors called exactly once (no N+1 findById calls)
        self::assertSame(1, $ancestorCallCount);
        // findByContentIds called exactly once (no N+1 findByContentAndLocale calls)
        self::assertSame(1, $translationBatchCallCount);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @param array<string, Content> $contents
     * @param array<string, list<ContentTranslation>> $translations
     * @param array<string, list<Content>> $ancestors
     */
    private function createGenerator(
        array $contents = [],
        array $translations = [],
        array $ancestors = [],
        string $defaultLocale = 'en',
    ): BreadcrumbGenerator {
        $config = new CmsConfig(defaultLocale: $defaultLocale);

        $contentRepo = new InMemoryBreadcrumbContentRepository($contents, $ancestors);
        $translationRepo = new InMemoryBreadcrumbTranslationRepository($translations);

        return new BreadcrumbGenerator($contentRepo, $translationRepo, $config);
    }

    private function createContent(string $id, ?string $parentId): Content
    {
        $now = new DateTimeImmutable(self::NOW);

        return new Content(
            id: $id,
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'author-1',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $now,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: null,
            parentId: $parentId,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
        );
    }

    private function createTranslation(
        string $contentId,
        string $locale,
        string $title,
        string $path,
    ): ContentTranslation {
        return new ContentTranslation(
            id: 'trans-' . $contentId . '-' . $locale,
            contentId: $contentId,
            locale: $locale,
            title: $title,
            slugSegment: basename($path),
            path: $path,
            body: '<p>Body</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Body',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }

    /**
     * @param array<string, Content> $contents
     * @param array<string, list<Content>> $ancestors
     */
    private function createContentRepo(
        array $contents,
        array $ancestors,
        int &$ancestorCallCount,
    ): ContentRepositoryInterface {
        return new class ($contents, $ancestors, $ancestorCallCount) implements ContentRepositoryInterface {
            /**
             * @param array<string, Content> $contents
             * @param array<string, list<Content>> $ancestors
             */
            public function __construct(
                private readonly array $contents,
                private readonly array $ancestors,
                private int &$ancestorCallCount,
            ) {}

            public function findById(string $id): ?Content
            {
                return $this->contents[$id] ?? null;
            }

            public function findByPath(string $locale, string $path, ?string $tenantId = null): ?Content
            {
                return null;
            }

            public function findPublished(string $locale, ?string $contentType = null, int $page = 1, int $perPage = 20, ?string $tenantId = null): PaginationResult
            {
                return new PaginationResult(items: [], total: 0, hasMore: false, perPage: $perPage);
            }

            public function findByIds(array $ids): array
            {
                $result = [];

                foreach ($ids as $id) {
                    if (isset($this->contents[$id])) {
                        $result[$id] = $this->contents[$id];
                    }
                }

                return $result;
            }

            public function findAncestors(string $contentId, int $maxDepth = 20): array
            {
                $this->ancestorCallCount++;

                return $this->ancestors[$contentId] ?? [];
            }

            public function save(Content $content): void {}

            public function delete(Content $content): void {}

            public function findDescendants(string $contentId): array
            {
                return [];
            }
        };
    }

    /**
     * @param array<string, list<ContentTranslation>> $translations
     */
    private function createTranslationRepo(
        array $translations,
        int &$batchCallCount,
    ): ContentTranslationRepositoryInterface {
        return new class ($translations, $batchCallCount) implements ContentTranslationRepositoryInterface {
            /** @param array<string, list<ContentTranslation>> $map */
            public function __construct(
                private readonly array $map,
                private int &$batchCallCount,
            ) {}

            public function findById(string $id): ?ContentTranslation
            {
                return null;
            }

            public function findByContentId(string $contentId): array
            {
                return $this->map[$contentId] ?? [];
            }

            public function findByContentAndLocale(string $contentId, string $locale): ?ContentTranslation
            {
                return null;
            }

            public function findByPath(string $locale, string $path, ?string $tenantId = null): ?ContentTranslation
            {
                return null;
            }

            public function findByContentIds(array $contentIds): array
            {
                $this->batchCallCount++;
                $grouped = [];

                foreach ($contentIds as $id) {
                    if (isset($this->map[$id])) {
                        $grouped[$id] = $this->map[$id];
                    }
                }

                return $grouped;
            }

            public function save(ContentTranslation $translation): void {}

            public function delete(string $id): void {}
        };
    }
}

/**
 * @internal In-memory content repository for breadcrumb tests.
 */
final class InMemoryBreadcrumbContentRepository implements ContentRepositoryInterface
{
    /**
     * @param array<string, Content> $contents
     * @param array<string, list<Content>> $ancestors
     */
    public function __construct(
        private readonly array $contents,
        private readonly array $ancestors = [],
    ) {}

    public function findById(string $id): ?Content
    {
        return $this->contents[$id] ?? null;
    }

    public function findByPath(string $locale, string $path, ?string $tenantId = null): ?Content
    {
        return null;
    }

    public function findPublished(string $locale, ?string $contentType = null, int $page = 1, int $perPage = 20, ?string $tenantId = null): PaginationResult
    {
        return new PaginationResult(items: [], total: 0, hasMore: false, perPage: $perPage);
    }

    public function findByIds(array $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            if (isset($this->contents[$id])) {
                $result[$id] = $this->contents[$id];
            }
        }

        return $result;
    }

    public function findAncestors(string $contentId, int $maxDepth = 20): array
    {
        return $this->ancestors[$contentId] ?? [];
    }

    public function save(Content $content): void {}

    public function delete(Content $content): void {}

    public function findDescendants(string $contentId): array
    {
        return [];
    }
}

/**
 * @internal In-memory translation repository for breadcrumb tests.
 */
final class InMemoryBreadcrumbTranslationRepository implements ContentTranslationRepositoryInterface
{
    /** @param array<string, list<ContentTranslation>> $map */
    public function __construct(private readonly array $map = []) {}

    public function findById(string $id): ?ContentTranslation
    {
        return null;
    }

    public function findByContentId(string $contentId): array
    {
        return $this->map[$contentId] ?? [];
    }

    public function findByContentAndLocale(string $contentId, string $locale): ?ContentTranslation
    {
        foreach ($this->map[$contentId] ?? [] as $t) {
            if ($t->locale === $locale) {
                return $t;
            }
        }

        return null;
    }

    public function findByPath(string $locale, string $path, ?string $tenantId = null): ?ContentTranslation
    {
        return null;
    }

    public function findByContentIds(array $contentIds): array
    {
        $grouped = [];

        foreach ($contentIds as $id) {
            if (isset($this->map[$id])) {
                $grouped[$id] = $this->map[$id];
            }
        }

        return $grouped;
    }

    public function save(ContentTranslation $translation): void {}

    public function delete(string $id): void {}
}
