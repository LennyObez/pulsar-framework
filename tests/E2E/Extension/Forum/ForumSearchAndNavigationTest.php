<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Internal\Service\ForumService;
use Pulsar\Extension\Forum\Internal\Service\TagService;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;

use function array_filter;
use function array_slice;
use function array_values;
use function in_array;
use function usort;

/**
 * E2E: Forum search and navigation — browse categories, list threads, filter by tag, paginate.
 */
#[CoversClass(ForumService::class)]
#[CoversClass(TagService::class)]
#[CoversClass(Category::class)]
#[CoversClass(Tag::class)]
#[Group('e2e-forum')]
final class ForumSearchAndNavigationTest extends TestCase
{
    #[Test]
    public function browseCategoryHierarchy(): void
    {
        $stack = $this->createNavigationStack();

        // Create root categories
        $general = Category::create(
            id: 'cat-general',
            slug: 'general-discussion',
            sortOrder: 1,
        );
        $support = Category::create(
            id: 'cat-support',
            slug: 'support',
            sortOrder: 2,
        );
        $stack->categories->save($general);
        $stack->categories->save($support);

        // Create a child category under support
        $phpSupport = Category::create(
            id: 'cat-php-support',
            slug: 'php-support',
            parentId: 'cat-support',
            sortOrder: 1,
        );
        $stack->categories->save($phpSupport);

        // Browse root categories
        $roots = $stack->categories->findRoots();
        self::assertCount(2, $roots);
        self::assertSame('general-discussion', $roots[0]->slug);
        self::assertSame('support', $roots[1]->slug);

        // Browse children of support
        $children = $stack->categories->findByParent('cat-support');
        self::assertCount(1, $children);
        self::assertSame('php-support', $children[0]->slug);
        self::assertSame('cat-support', $children[0]->parentId);
    }

    #[Test]
    public function listThreadsByCategoryWithPagination(): void
    {
        $stack = $this->createNavigationStack();

        // Seed a category
        $category = Category::create(id: 'cat-general', slug: 'general');
        $stack->categories->save($category);

        // Create 5 threads in the category
        for ($i = 1; $i <= 5; $i++) {
            $stack->forumService->createThread(
                categoryId: 'cat-general',
                authorId: 'user-alice',
                title: "Discussion thread {$i}",
                slug: "discussion-thread-{$i}",
                type: ThreadType::Discussion,
                body: "Content of thread {$i}",
                bodyHtml: "<p>Content of thread {$i}</p>",
                ipHash: "iphash-cat-{$i}",
                userAgentHash: "uahash-cat-{$i}",
            );
        }

        // Page 1: 3 items per page
        $page1 = $stack->threads->findByCategory('cat-general', page: 1, perPage: 3);
        self::assertCount(3, $page1->items);
        self::assertSame(5, $page1->total);
        self::assertTrue($page1->hasMore);

        // Page 2: remaining 2 items
        $page2 = $stack->threads->findByCategory('cat-general', page: 2, perPage: 3);
        self::assertCount(2, $page2->items);
        self::assertSame(5, $page2->total);
        self::assertFalse($page2->hasMore);
    }

    #[Test]
    public function filterThreadsByTagUsingTagService(): void
    {
        $stack = $this->createNavigationStack();
        $stack->categories->save(Category::create(id: 'cat-general', slug: 'general'));

        // Create tags
        $phpTag = $stack->tagService->createTag('PHP', 'php', 'PHP language topics');
        $jsTag = $stack->tagService->createTag('JavaScript', 'javascript', 'JS topics');

        // Create threads
        $thread1 = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'PHP routing question',
            slug: 'php-routing-question',
            type: ThreadType::Question,
            body: 'How does routing work?',
            bodyHtml: '<p>How does routing work?</p>',
            ipHash: 'iphash-tag1',
            userAgentHash: 'uahash-tag1',
        );

        $thread2 = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-bob',
            title: 'JS framework comparison',
            slug: 'js-framework-comparison',
            type: ThreadType::Discussion,
            body: 'Which JS framework is best?',
            bodyHtml: '<p>Which JS framework is best?</p>',
            ipHash: 'iphash-tag2',
            userAgentHash: 'uahash-tag2',
        );

        // Attach tags via service
        $stack->tagService->attachTags($thread1->id, [$phpTag->id]);
        $stack->tagService->attachTags($thread2->id, [$jsTag->id]);

        // Also index tags in the thread repository for findByTag() lookups
        $stack->threads->indexTag($phpTag->id, $thread1->id);
        $stack->threads->indexTag($jsTag->id, $thread2->id);

        // Filter by PHP tag
        $phpThreads = $stack->threads->findByTag($phpTag->id);
        self::assertSame(1, $phpThreads->total);
        self::assertCount(1, $phpThreads->items);
        self::assertSame($thread1->id, $phpThreads->items[0]->id);

        // Filter by JS tag
        $jsThreads = $stack->threads->findByTag($jsTag->id);
        self::assertSame(1, $jsThreads->total);
        self::assertSame($thread2->id, $jsThreads->items[0]->id);

        // Verify tag usage counts incremented
        $updatedPhpTag = $stack->tags->findById($phpTag->id);
        self::assertNotNull($updatedPhpTag);
        self::assertSame(1, $updatedPhpTag->usageCount);
    }

    #[Test]
    public function findRecentThreadsAcrossCategories(): void
    {
        $stack = $this->createNavigationStack();
        $stack->categories->save(Category::create(id: 'cat-general', slug: 'general'));
        $stack->categories->save(Category::create(id: 'cat-support', slug: 'support'));

        // Create threads in different categories
        $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'General thread',
            slug: 'general-thread',
            type: ThreadType::Discussion,
            body: 'General content',
            bodyHtml: '<p>General content</p>',
            ipHash: 'iphash-recent1',
            userAgentHash: 'uahash-recent1',
        );

        $stack->forumService->createThread(
            categoryId: 'cat-support',
            authorId: 'user-bob',
            title: 'Support thread',
            slug: 'support-thread',
            type: ThreadType::Question,
            body: 'Need help',
            bodyHtml: '<p>Need help</p>',
            ipHash: 'iphash-recent2',
            userAgentHash: 'uahash-recent2',
        );

        $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-charlie',
            title: 'Another general thread',
            slug: 'another-general-thread',
            type: ThreadType::Showcase,
            body: 'Look at this',
            bodyHtml: '<p>Look at this</p>',
            ipHash: 'iphash-recent3',
            userAgentHash: 'uahash-recent3',
        );

        // Recent threads should return all threads across categories
        $recent = $stack->threads->findRecent(page: 1, perPage: 10);
        self::assertSame(3, $recent->total);
        self::assertCount(3, $recent->items);
    }

    #[Test]
    public function findThreadsByAuthor(): void
    {
        $stack = $this->createNavigationStack();
        $stack->categories->save(Category::create(id: 'cat-general', slug: 'general'));

        // Alice creates 3 threads, Bob creates 1
        for ($i = 1; $i <= 3; $i++) {
            $stack->forumService->createThread(
                categoryId: 'cat-general',
                authorId: 'user-alice',
                title: "Alice thread {$i}",
                slug: "alice-thread-{$i}",
                type: ThreadType::Discussion,
                body: "Alice body {$i}",
                bodyHtml: "<p>Alice body {$i}</p>",
                ipHash: "iphash-alice-{$i}",
                userAgentHash: "uahash-alice-{$i}",
            );
        }

        $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-bob',
            title: 'Bobs thread',
            slug: 'bobs-thread',
            type: ThreadType::Discussion,
            body: 'Bobs content',
            bodyHtml: '<p>Bobs content</p>',
            ipHash: 'iphash-bob-1',
            userAgentHash: 'uahash-bob-1',
        );

        $aliceThreads = $stack->threads->findByAuthor('user-alice');
        self::assertSame(3, $aliceThreads->total);

        $bobThreads = $stack->threads->findByAuthor('user-bob');
        self::assertSame(1, $bobThreads->total);
    }

    #[Test]
    public function tagCreationAndPopularTagsListing(): void
    {
        $stack = $this->createNavigationStack();

        // Create several tags
        $stack->tagService->createTag('PHP', 'php', 'PHP language');
        $stack->tagService->createTag('JavaScript', 'javascript', 'JS language');
        $stack->tagService->createTag('TypeScript', 'typescript', 'TS language');
        $stack->tagService->createTag('Rust', 'rust', 'Rust language');
        $stack->tagService->createTag('Go', 'go', 'Go language');

        // Get popular tags (limited to 3)
        $popular = $stack->tagService->findPopular(limit: 3);
        self::assertCount(3, $popular);

        // All tags returned
        $all = $stack->tagService->findPopular(limit: 20);
        self::assertCount(5, $all);

        // Verify tag properties
        $phpTag = $stack->tags->findBySlug('php');
        self::assertNotNull($phpTag);
        self::assertSame('PHP', $phpTag->name);
        self::assertSame('PHP language', $phpTag->description);
        self::assertSame(0, $phpTag->usageCount);
    }

    private function createNavigationStack(): NavigationTestStack
    {
        $threads = new E2EThreadRepository();
        $posts = new E2EPostRepository();
        $profiles = new E2EForumProfileRepository();
        $events = new E2EEventDispatcher();
        $config = ForumConfig::fromArray([]);
        $badges = new E2EBadgeService();
        $reputationService = new E2EReputationService($profiles);
        $categories = new E2ENavigationCategoryRepository();
        $tags = new E2ENavigationTagRepository();

        $forumService = new ForumService(
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            reputationService: $reputationService,
            badgeService: $badges,
            events: $events,
            config: $config,
            categories: $categories,
        );

        $tagService = new TagService(tags: $tags);

        return new NavigationTestStack(
            forumService: $forumService,
            tagService: $tagService,
            threads: $threads,
            posts: $posts,
            categories: $categories,
            tags: $tags,
        );
    }
}

/**
 * @internal Shared stack for search and navigation E2E tests.
 */
final readonly class NavigationTestStack
{
    public function __construct(
        public ForumService $forumService,
        public TagService $tagService,
        public E2EThreadRepository $threads,
        public E2EPostRepository $posts,
        public E2ENavigationCategoryRepository $categories,
        public E2ENavigationTagRepository $tags,
    ) {}
}

/**
 * @internal In-memory category repository for navigation E2E tests.
 */
final class E2ENavigationCategoryRepository implements CategoryRepositoryInterface
{
    /** @var array<string, Category> */
    private array $categories = [];

    #[Override]
    public function findById(string $id): ?Category
    {
        return $this->categories[$id] ?? null;
    }

    #[Override]
    public function findBySlug(string $slug, ?string $tenantId = null): ?Category
    {
        foreach ($this->categories as $category) {
            if ($category->slug === $slug) {
                return $category;
            }
        }

        return null;
    }

    #[Override]
    public function findRoots(?string $tenantId = null): array
    {
        $roots = array_values(array_filter(
            $this->categories,
            static fn(Category $c) => $c->parentId === null,
        ));

        usort($roots, static fn(Category $a, Category $b) => $a->sortOrder <=> $b->sortOrder);

        return $roots;
    }

    #[Override]
    public function findByParent(string $parentId): array
    {
        $children = array_values(array_filter(
            $this->categories,
            static fn(Category $c) => $c->parentId === $parentId,
        ));

        usort($children, static fn(Category $a, Category $b) => $a->sortOrder <=> $b->sortOrder);

        return $children;
    }

    #[Override]
    public function save(Category $category): void
    {
        $this->categories[$category->id] = $category;
    }

    #[Override]
    public function delete(Category $category): void
    {
        unset($this->categories[$category->id]);
    }
}

/**
 * @internal In-memory tag repository for navigation E2E tests.
 */
final class E2ENavigationTagRepository implements TagRepositoryInterface
{
    /** @var array<string, Tag> */
    private array $tags = [];

    /** @var array<string, list<string>> tagId -> list<threadId> */
    private array $threadIndex = [];

    #[Override]
    public function findById(string $id): ?Tag
    {
        return $this->tags[$id] ?? null;
    }

    #[Override]
    public function findBySlug(string $slug): ?Tag
    {
        foreach ($this->tags as $tag) {
            if ($tag->slug === $slug) {
                return $tag;
            }
        }

        return null;
    }

    #[Override]
    public function findAll(): array
    {
        $tags = array_values($this->tags);
        usort($tags, static fn(Tag $a, Tag $b) => $b->usageCount <=> $a->usageCount);

        return $tags;
    }

    #[Override]
    public function findByThread(string $threadId): array
    {
        $result = [];

        foreach ($this->threadIndex as $tagId => $threadIds) {
            if (in_array($threadId, $threadIds, true) && isset($this->tags[$tagId])) {
                $result[] = $this->tags[$tagId];
            }
        }

        return $result;
    }

    #[Override]
    public function attachToThread(string $tagId, string $threadId): void
    {
        $this->threadIndex[$tagId][] = $threadId;
    }

    #[Override]
    public function detachFromThread(string $tagId, string $threadId): void
    {
        if (!isset($this->threadIndex[$tagId])) {
            return;
        }

        $this->threadIndex[$tagId] = array_values(array_filter(
            $this->threadIndex[$tagId],
            static fn(string $id) => $id !== $threadId,
        ));
    }

    #[Override]
    public function findPopular(int $limit = 20): array
    {
        $all = $this->findAll();

        return array_slice($all, 0, $limit);
    }

    #[Override]
    public function save(Tag $tag): void
    {
        $this->tags[$tag->id] = $tag;
    }

    #[Override]
    public function delete(Tag $tag): void
    {
        unset($this->tags[$tag->id]);
    }
}
