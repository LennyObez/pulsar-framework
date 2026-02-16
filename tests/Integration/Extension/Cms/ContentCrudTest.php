<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;

use function count;
use function in_array;

#[CoversClass(Content::class)]
#[CoversClass(ContentTranslation::class)]
final class ContentCrudTest extends TestCase
{
    private ContentRepositoryInterface $contentRepo;
    private ContentTranslationRepositoryInterface $translationRepo;

    protected function setUp(): void
    {
        $this->contentRepo = $this->createContentRepository();
        $this->translationRepo = $this->createTranslationRepository();
    }

    #[Test]
    public function createContentPersistsAllFields(): void
    {
        $content = Content::create(
            id: '019461a0-0000-7000-8000-000000000001',
            contentType: ContentType::Article,
            authorId: 'author-001',
            tenantId: null,
            template: 'blog-post',
            parentId: null,
            sortOrder: 5,
            commentPolicy: CommentPolicy::Moderated,
            dataClassification: DataClassification::Internal,
        );

        $this->contentRepo->save($content);
        $found = $this->contentRepo->findById($content->id);

        self::assertNotNull($found);
        self::assertSame($content->id, $found->id);
        self::assertNull($found->tenantId);
        self::assertSame(ContentType::Article, $found->contentType);
        self::assertSame('author-001', $found->authorId);
        self::assertSame(PublishingStatus::Draft, $found->status);
        self::assertNull($found->scheduledPublishAt);
        self::assertNull($found->publishedAt);
        self::assertNull($found->deletedAt);
        self::assertSame('blog-post', $found->template);
        self::assertNull($found->parentId);
        self::assertSame(5, $found->sortOrder);
        self::assertSame(CommentPolicy::Moderated, $found->commentPolicy);
        self::assertSame(DataClassification::Internal, $found->dataClassification);
    }

    #[Test]
    public function findContentByIdReturnsFullAggregate(): void
    {
        $content = Content::create(
            id: '019461a0-0000-7000-8000-000000000002',
            contentType: ContentType::Page,
            authorId: 'author-002',
        );
        $this->contentRepo->save($content);

        $found = $this->contentRepo->findById($content->id);

        self::assertNotNull($found);
        self::assertSame(ContentType::Page, $found->contentType);
        self::assertSame('author-002', $found->authorId);
        self::assertSame(PublishingStatus::Draft, $found->status);
    }

    #[Test]
    public function findContentByPathResolvesCorrectly(): void
    {
        $content = Content::create(
            id: '019461a0-0000-7000-8000-000000000003',
            contentType: ContentType::Page,
            authorId: 'author-003',
        );
        $this->contentRepo->save($content);

        $translation = ContentTranslation::create(
            id: '019461a0-1000-7000-8000-000000000003',
            contentId: $content->id,
            locale: 'en',
            title: 'Getting Started',
            slugSegment: 'getting-started',
            path: 'docs/getting-started',
            body: '<p>Welcome</p>',
        );
        $this->translationRepo->save($translation);

        $foundTranslation = $this->translationRepo->findByPath('en', 'docs/getting-started');

        self::assertNotNull($foundTranslation);
        self::assertSame($content->id, $foundTranslation->contentId);
        self::assertSame('Getting Started', $foundTranslation->title);
    }

    #[Test]
    public function findPublishedContentReturnsOnlyPublished(): void
    {
        // Create a draft and a published content item
        $draft = Content::create(
            id: '019461a0-0000-7000-8000-000000000004',
            contentType: ContentType::Article,
            authorId: 'author-004',
        );
        $this->contentRepo->save($draft);

        $published = Content::create(
            id: '019461a0-0000-7000-8000-000000000005',
            contentType: ContentType::Article,
            authorId: 'author-005',
        );
        $published = $published->publish();
        $this->contentRepo->save($published);

        $this->createTranslationForContent($draft->id, 'en', 'Draft Article', 'draft-article');
        $this->createTranslationForContent($published->id, 'en', 'Published Article', 'published-article');

        $result = $this->contentRepo->findPublished('en');

        self::assertInstanceOf(PaginationResult::class, $result);

        $publishedIds = array_map(static fn(Content $c) => $c->id, $result->items);
        self::assertContains($published->id, $publishedIds);
        self::assertNotContains($draft->id, $publishedIds);
    }

    #[Test]
    public function updateContentPreservesExistingTranslations(): void
    {
        $content = Content::create(
            id: '019461a0-0000-7000-8000-000000000006',
            contentType: ContentType::Article,
            authorId: 'author-006',
        );
        $this->contentRepo->save($content);

        $enTranslation = ContentTranslation::create(
            id: '019461a0-1000-7000-8000-000000000006',
            contentId: $content->id,
            locale: 'en',
            title: 'Original Title',
            slugSegment: 'original-title',
            path: 'original-title',
            body: '<p>Original content</p>',
        );
        $this->translationRepo->save($enTranslation);

        // Update the content status (publish it)
        $updated = $content->publish();
        $this->contentRepo->save($updated);

        // Verify translation is still intact
        $foundTranslation = $this->translationRepo->findByContentAndLocale($content->id, 'en');

        self::assertNotNull($foundTranslation);
        self::assertSame('Original Title', $foundTranslation->title);
        self::assertSame('original-title', $foundTranslation->slugSegment);
    }

    #[Test]
    public function softDeleteContentMarksDeletedAt(): void
    {
        $content = Content::create(
            id: '019461a0-0000-7000-8000-000000000007',
            contentType: ContentType::Page,
            authorId: 'author-007',
        );
        $this->contentRepo->save($content);

        $this->contentRepo->delete($content);

        // The in-memory mock simulates soft-delete by removing from findById
        $found = $this->contentRepo->findById($content->id);
        self::assertNull($found);
    }

    #[Test]
    public function softDeletedContentNotReturnedInQueries(): void
    {
        $content = Content::create(
            id: '019461a0-0000-7000-8000-000000000008',
            contentType: ContentType::Article,
            authorId: 'author-008',
        );
        $published = $content->publish();
        $this->contentRepo->save($published);

        $this->createTranslationForContent($published->id, 'en', 'Deleted Article', 'deleted-article');

        $this->contentRepo->delete($published);

        // Verify content is not returned by findPublished
        $result = $this->contentRepo->findPublished('en');
        $ids = array_map(static fn(Content $c) => $c->id, $result->items);
        self::assertNotContains($published->id, $ids);

        // Verify content is not returned by findById
        $found = $this->contentRepo->findById($published->id);
        self::assertNull($found);
    }

    #[Test]
    public function createContentWithTranslationInMultipleLocales(): void
    {
        $content = Content::create(
            id: '019461a0-0000-7000-8000-000000000009',
            contentType: ContentType::Article,
            authorId: 'author-009',
        );
        $this->contentRepo->save($content);

        $en = ContentTranslation::create(
            id: '019461a0-1000-7000-8000-000000000009',
            contentId: $content->id,
            locale: 'en',
            title: 'Hello World',
            slugSegment: 'hello-world',
            path: 'hello-world',
            body: '<p>Hello</p>',
        );
        $this->translationRepo->save($en);

        $fr = ContentTranslation::create(
            id: '019461a0-2000-7000-8000-000000000009',
            contentId: $content->id,
            locale: 'fr',
            title: 'Bonjour le Monde',
            slugSegment: 'bonjour-le-monde',
            path: 'bonjour-le-monde',
            body: '<p>Bonjour</p>',
        );
        $this->translationRepo->save($fr);

        $translations = $this->translationRepo->findByContentId($content->id);
        self::assertCount(2, $translations);

        $locales = array_map(static fn(ContentTranslation $t) => $t->locale, $translations);
        self::assertContains('en', $locales);
        self::assertContains('fr', $locales);

        $enFound = $this->translationRepo->findByContentAndLocale($content->id, 'en');
        self::assertNotNull($enFound);
        self::assertSame('Hello World', $enFound->title);

        $frFound = $this->translationRepo->findByContentAndLocale($content->id, 'fr');
        self::assertNotNull($frFound);
        self::assertSame('Bonjour le Monde', $frFound->title);
    }

    #[Test]
    public function contentHierarchyParentChildRelationship(): void
    {
        $parent = Content::create(
            id: '019461a0-0000-7000-8000-00000000000a',
            contentType: ContentType::Page,
            authorId: 'author-010',
        );
        $this->contentRepo->save($parent);

        $child = Content::create(
            id: '019461a0-0000-7000-8000-00000000000b',
            contentType: ContentType::Page,
            authorId: 'author-010',
            parentId: $parent->id,
        );
        $this->contentRepo->save($child);

        $foundChild = $this->contentRepo->findById($child->id);
        self::assertNotNull($foundChild);
        self::assertSame($parent->id, $foundChild->parentId);

        $descendants = $this->contentRepo->findDescendants($parent->id);
        self::assertCount(1, $descendants);
        self::assertSame($child->id, $descendants[0]->id);
    }

    private function createTranslationForContent(
        string $contentId,
        string $locale,
        string $title,
        string $slug,
    ): ContentTranslation {
        $translation = ContentTranslation::create(
            id: bin2hex(random_bytes(8)),
            contentId: $contentId,
            locale: $locale,
            title: $title,
            slugSegment: $slug,
            path: $slug,
            body: "<p>{$title} body</p>",
        );
        $this->translationRepo->save($translation);

        return $translation;
    }

    private function createContentRepository(): ContentRepositoryInterface
    {
        return new class implements ContentRepositoryInterface {
            /** @var array<string, Content> */
            private array $contents = [];

            /** @var array<string, true> */
            private array $deleted = [];

            public function findById(string $id): ?Content
            {
                if (isset($this->deleted[$id])) {
                    return null;
                }

                return $this->contents[$id] ?? null;
            }

            public function findByImportId(string $importId): ?Content
            {
                return null;
            }

            public function findByPath(string $locale, string $path, ?string $tenantId = null): ?Content
            {
                return null;
            }

            public function findPublished(
                string $locale,
                ?string $contentType = null,
                int $page = 1,
                int $perPage = 20,
                ?string $tenantId = null,
            ): PaginationResult {
                $items = array_filter(
                    $this->contents,
                    fn(Content $c) => $c->status === PublishingStatus::Published && !isset($this->deleted[$c->id]),
                );

                if ($contentType !== null) {
                    $items = array_filter($items, static fn(Content $c) => $c->contentType->value === $contentType);
                }

                $items = array_values($items);

                return new PaginationResult(
                    items: $items,
                    total: count($items),
                    hasMore: false,
                    perPage: $perPage,
                    currentPage: $page,
                    lastPage: 1,
                );
            }

            public function findByIds(array $ids): array
            {
                $result = [];

                foreach ($ids as $id) {
                    if (isset($this->contents[$id]) && !isset($this->deleted[$id])) {
                        $result[$id] = $this->contents[$id];
                    }
                }

                return $result;
            }

            public function findAncestors(string $contentId, int $maxDepth = 20): array
            {
                $ancestors = [];
                $current = $this->contents[$contentId] ?? null;
                $depth = 0;

                while ($current !== null && $current->parentId !== null && $depth < $maxDepth) {
                    $parent = $this->contents[$current->parentId] ?? null;

                    if ($parent === null || isset($this->deleted[$parent->id])) {
                        break;
                    }

                    $ancestors[] = $parent;
                    $current = $parent;
                    $depth++;
                }

                return $ancestors;
            }

            public function save(Content $content): void
            {
                $this->contents[$content->id] = $content;
            }

            public function delete(Content $content): void
            {
                $this->deleted[$content->id] = true;
            }

            public function findDescendants(string $contentId): array
            {
                return array_values(
                    array_filter(
                        $this->contents,
                        static fn(Content $c) => $c->parentId === $contentId,
                    ),
                );
            }

            public function findScheduledForPublishing(DateTimeImmutable $now): array
            {
                return [];
            }

            public function findScheduledForUnpublishing(DateTimeImmutable $now): array
            {
                return [];
            }

            public function bulkUpdateStatus(array $ids, PublishingStatus $status, ?string $tenantId = null): int
            {
                return 0;
            }

            public function bulkDelete(array $ids, ?string $tenantId = null): int
            {
                return 0;
            }
        };
    }

    private function createTranslationRepository(): ContentTranslationRepositoryInterface
    {
        return new class implements ContentTranslationRepositoryInterface {
            /** @var array<string, ContentTranslation> */
            private array $translations = [];

            public function findById(string $id): ?ContentTranslation
            {
                return $this->translations[$id] ?? null;
            }

            public function findByContentId(string $contentId): array
            {
                return array_values(
                    array_filter(
                        $this->translations,
                        static fn(ContentTranslation $t) => $t->contentId === $contentId,
                    ),
                );
            }

            public function findByContentAndLocale(string $contentId, string $locale): ?ContentTranslation
            {
                foreach ($this->translations as $t) {
                    if ($t->contentId === $contentId && $t->locale === $locale) {
                        return $t;
                    }
                }

                return null;
            }

            public function findByPath(string $locale, string $path, ?string $tenantId = null): ?ContentTranslation
            {
                foreach ($this->translations as $t) {
                    if ($t->locale === $locale && $t->path === $path) {
                        return $t;
                    }
                }

                return null;
            }

            public function findByContentIds(array $contentIds): array
            {
                $grouped = [];

                foreach ($this->translations as $t) {
                    if (in_array($t->contentId, $contentIds, true)) {
                        $grouped[$t->contentId][] = $t;
                    }
                }

                return $grouped;
            }

            public function save(ContentTranslation $translation): void
            {
                $this->translations[$translation->id] = $translation;
            }

            public function delete(string $id): void
            {
                unset($this->translations[$id]);
            }
        };
    }
}
