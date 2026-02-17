<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\ImportExport;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslation;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\ImportExport\ForumImportExportProvider;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ImportRequest;

use function in_array;
use function json_encode;

#[CoversClass(ForumImportExportProvider::class)]
final class ForumImportExportProviderTest extends TestCase
{
    private ForumImportExportProvider $provider;
    private CategoryRepositoryInterface&Stub $categoryRepo;
    private CategoryTranslationRepositoryInterface&Stub $translationRepo;
    private ThreadRepositoryInterface&Stub $threadRepo;
    private TagRepositoryInterface&Stub $tagRepo;

    protected function setUp(): void
    {
        $this->categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $this->translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $this->threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $this->tagRepo = $this->createStub(TagRepositoryInterface::class);

        $this->provider = new ForumImportExportProvider(
            $this->categoryRepo,
            $this->translationRepo,
            $this->threadRepo,
            $this->tagRepo,
        );
    }

    #[Test]
    public function nameReturnsForum(): void
    {
        self::assertSame('forum', $this->provider->name());
    }

    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        self::assertSame('Forum', $this->provider->label());
    }

    #[Test]
    public function supportsJsonFormat(): void
    {
        self::assertSame(['json'], $this->provider->supportedFormats());
    }

    #[Test]
    public function exportCategoriesWithTranslations(): void
    {
        $now = new DateTimeImmutable();
        $category = new Category(
            id: 'cat-1',
            tenantId: null,
            parentId: null,
            slug: 'general',
            sortOrder: 0,
            isLocked: false,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->categoryRepo->method('findRoots')->willReturn([$category]);
        $this->categoryRepo->method('findByParent')->willReturn([]);

        $translation = new CategoryTranslation(
            id: 'tr-1',
            categoryId: 'cat-1',
            locale: 'en',
            name: 'General',
            description: 'General discussion',
        );

        $this->translationRepo->method('findByCategory')->willReturn([$translation]);

        $this->threadRepo->method('findRecent')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 10000),
        );
        $this->tagRepo->method('findAll')->willReturn([]);

        $result = $this->provider->export(new ExportRequest());

        self::assertSame('forum', $result->providerName);
        self::assertNotEmpty($result->evidenceHash);
        self::assertArrayHasKey('categories', $result->data);
        self::assertCount(1, $result->data['categories']);
        self::assertSame('general', $result->data['categories'][0]['slug']);
        self::assertArrayHasKey('en', $result->data['categories'][0]['translations']);
    }

    #[Test]
    public function exportTags(): void
    {
        $tag = Tag::create(id: 'tag-1', slug: 'php', name: 'PHP', description: 'PHP language');

        $this->categoryRepo->method('findRoots')->willReturn([]);
        $this->threadRepo->method('findRecent')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 10000),
        );
        $this->tagRepo->method('findAll')->willReturn([$tag]);

        $result = $this->provider->export(new ExportRequest(entityTypes: ['tags']));

        self::assertArrayHasKey('tags', $result->data);
        self::assertCount(1, $result->data['tags']);
        self::assertSame('php', $result->data['tags'][0]['slug']);
    }

    #[Test]
    public function importCategoriesInDryRunMode(): void
    {
        $this->categoryRepo->method('findBySlug')->willReturn(null);

        $content = json_encode([
            'categories' => [
                ['slug' => 'new-cat', 'sort_order' => 0],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content, dryRun: true));

        self::assertSame('forum', $result->providerName);
        self::assertTrue($result->dryRun);
        self::assertSame(['categories' => 1], $result->created);
    }

    #[Test]
    public function importSkipsExistingCategories(): void
    {
        $existing = Category::create(id: 'cat-1', slug: 'existing');
        $this->categoryRepo->method('findBySlug')->willReturn($existing);

        $content = json_encode([
            'categories' => [
                ['slug' => 'existing'],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content));

        self::assertSame(['categories' => 1], $result->skipped);
        self::assertSame([], $result->created);
    }

    #[Test]
    public function importWarnsOnMissingSlug(): void
    {
        $content = json_encode([
            'categories' => [
                ['name' => 'No Slug'],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content));

        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('missing slug', $result->warnings[0]);
    }

    #[Test]
    public function importCategoriesWithTranslations(): void
    {
        $categoryRepo = $this->createMock(CategoryRepositoryInterface::class);
        $translationRepo = $this->createMock(CategoryTranslationRepositoryInterface::class);

        $categoryRepo->method('findBySlug')->willReturn(null);
        $categoryRepo->expects(self::once())->method('save');

        // Expect two translations to be saved (en + fr)
        $translationRepo->expects(self::exactly(2))->method('save')
            ->with(self::callback(function (CategoryTranslation $t): bool {
                // Verify translation data is correct
                return $t->categoryId !== ''
                    && in_array($t->locale, ['en', 'fr'], true)
                    && $t->name !== '';
            }));

        $provider = new ForumImportExportProvider(
            $categoryRepo,
            $translationRepo,
            $this->createStub(ThreadRepositoryInterface::class),
            $this->createStub(TagRepositoryInterface::class),
        );

        $content = json_encode([
            'categories' => [
                [
                    'id' => 'cat-import-1',
                    'slug' => 'imported-cat',
                    'sort_order' => 1,
                    'translations' => [
                        'en' => ['name' => 'General', 'description' => 'General discussion'],
                        'fr' => ['name' => 'General', 'description' => 'Discussion generale'],
                    ],
                ],
            ],
        ]);

        $result = $provider->import(new ImportRequest(content: $content, dryRun: false));

        self::assertSame(['categories' => 1], $result->created);
    }

    #[Test]
    public function importCategoriesSkipsTranslationsWithEmptyName(): void
    {
        $categoryRepo = $this->createMock(CategoryRepositoryInterface::class);
        $translationRepo = $this->createMock(CategoryTranslationRepositoryInterface::class);

        $categoryRepo->method('findBySlug')->willReturn(null);
        $categoryRepo->expects(self::once())->method('save');

        // Only the translation with a non-empty name should be saved
        $translationRepo->expects(self::once())->method('save');

        $provider = new ForumImportExportProvider(
            $categoryRepo,
            $translationRepo,
            $this->createStub(ThreadRepositoryInterface::class),
            $this->createStub(TagRepositoryInterface::class),
        );

        $content = json_encode([
            'categories' => [
                [
                    'slug' => 'cat-with-empty',
                    'translations' => [
                        'en' => ['name' => 'Valid Name', 'description' => 'Desc'],
                        'fr' => ['name' => '', 'description' => 'No name locale'],
                    ],
                ],
            ],
        ]);

        $result = $provider->import(new ImportRequest(content: $content, dryRun: false));

        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('empty name', $result->warnings[0]);
    }

    #[Test]
    public function importCategoriesSkipsTranslationsInDryRun(): void
    {
        $this->categoryRepo->method('findBySlug')->willReturn(null);

        $content = json_encode([
            'categories' => [
                [
                    'slug' => 'dry-run-cat',
                    'translations' => [
                        'en' => ['name' => 'Test', 'description' => 'Test desc'],
                    ],
                ],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content, dryRun: true));

        // In dry-run, count is incremented but no save calls happen
        self::assertSame(['categories' => 1], $result->created);
        self::assertTrue($result->dryRun);
    }

    #[Test]
    public function importThreadsInDryRunMode(): void
    {
        $this->threadRepo->method('findBySlug')->willReturn(null);

        $content = json_encode([
            'threads' => [
                [
                    'slug' => 'hello-world',
                    'title' => 'Hello World',
                    'category_id' => 'cat-1',
                    'author_id' => 'user-1',
                    'type' => 'discussion',
                ],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content, dryRun: true));

        self::assertSame('forum', $result->providerName);
        self::assertTrue($result->dryRun);
        self::assertSame(['threads' => 1], $result->created);
    }

    #[Test]
    public function importThreadsSkipsExistingBySlug(): void
    {
        $existing = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Existing Thread',
            slug: 'existing-thread',
            type: ThreadType::Discussion,
            ipHash: '',
            userAgentHash: '',
        );
        $this->threadRepo->method('findBySlug')->willReturn($existing);

        $content = json_encode([
            'threads' => [
                [
                    'slug' => 'existing-thread',
                    'title' => 'Existing Thread',
                    'category_id' => 'cat-1',
                ],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content));

        self::assertSame(['threads' => 1], $result->skipped);
        self::assertSame([], $result->created);
    }

    #[Test]
    public function importThreadsWarnsOnMissingSlug(): void
    {
        $content = json_encode([
            'threads' => [
                ['title' => 'No Slug Thread'],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content));

        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('missing slug', $result->warnings[0]);
    }

    #[Test]
    public function importThreadsWarnsOnMissingTitle(): void
    {
        $content = json_encode([
            'threads' => [
                ['slug' => 'no-title'],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content));

        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('missing title', $result->warnings[0]);
    }

    #[Test]
    public function importThreadsCreatesWithCorrectType(): void
    {
        $threadRepo = $this->createMock(ThreadRepositoryInterface::class);
        $threadRepo->method('findBySlug')->willReturn(null);
        $threadRepo->expects(self::once())->method('save')
            ->with(self::callback(function (Thread $thread): bool {
                return $thread->slug === 'question-thread'
                    && $thread->type === ThreadType::Question
                    && $thread->title === 'How to X?';
            }));

        $provider = new ForumImportExportProvider(
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(CategoryTranslationRepositoryInterface::class),
            $threadRepo,
            $this->createStub(TagRepositoryInterface::class),
        );

        $content = json_encode([
            'threads' => [
                [
                    'slug' => 'question-thread',
                    'title' => 'How to X?',
                    'category_id' => 'cat-1',
                    'type' => 'question',
                ],
            ],
        ]);

        $result = $provider->import(new ImportRequest(content: $content, dryRun: false));

        self::assertSame(['threads' => 1], $result->created);
    }

    #[Test]
    public function importThreadsDefaultsToDiscussionType(): void
    {
        $threadRepo = $this->createMock(ThreadRepositoryInterface::class);
        $threadRepo->method('findBySlug')->willReturn(null);
        $threadRepo->expects(self::once())->method('save')
            ->with(self::callback(function (Thread $thread): bool {
                return $thread->type === ThreadType::Discussion;
            }));

        $provider = new ForumImportExportProvider(
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(CategoryTranslationRepositoryInterface::class),
            $threadRepo,
            $this->createStub(TagRepositoryInterface::class),
        );

        $content = json_encode([
            'threads' => [
                [
                    'slug' => 'no-type-thread',
                    'title' => 'Default Type Thread',
                    'category_id' => 'cat-1',
                ],
            ],
        ]);

        $result = $provider->import(new ImportRequest(content: $content, dryRun: false));

        self::assertSame(['threads' => 1], $result->created);
    }

    #[Test]
    public function importMixedEntityTypes(): void
    {
        $this->categoryRepo->method('findBySlug')->willReturn(null);
        $this->threadRepo->method('findBySlug')->willReturn(null);
        $this->tagRepo->method('findBySlug')->willReturn(null);

        $content = json_encode([
            'categories' => [
                ['slug' => 'general', 'sort_order' => 0],
            ],
            'threads' => [
                ['slug' => 'first-post', 'title' => 'First Post', 'category_id' => 'cat-1'],
            ],
            'tags' => [
                ['slug' => 'php', 'name' => 'PHP'],
            ],
        ]);

        $result = $this->provider->import(new ImportRequest(content: $content, dryRun: true));

        self::assertSame(1, $result->created['categories']);
        self::assertSame(1, $result->created['threads']);
        self::assertSame(1, $result->created['tags']);
    }

    #[Test]
    public function importTagsWithExecute(): void
    {
        $tagRepo = $this->createMock(TagRepositoryInterface::class);
        $tagRepo->method('findBySlug')->willReturn(null);
        $tagRepo->expects(self::once())->method('save');

        $provider = new ForumImportExportProvider(
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(CategoryTranslationRepositoryInterface::class),
            $this->createStub(ThreadRepositoryInterface::class),
            $tagRepo,
        );

        $content = json_encode([
            'tags' => [
                ['slug' => 'php', 'name' => 'PHP', 'description' => 'PHP language'],
            ],
        ]);

        $result = $provider->import(new ImportRequest(content: $content, dryRun: false));

        self::assertSame(['tags' => 1], $result->created);
    }

    #[Test]
    public function schemaDescribesAllEntityTypes(): void
    {
        $schema = $this->provider->schema();

        self::assertArrayHasKey('categories', $schema);
        self::assertArrayHasKey('threads', $schema);
        self::assertArrayHasKey('tags', $schema);
    }
}
