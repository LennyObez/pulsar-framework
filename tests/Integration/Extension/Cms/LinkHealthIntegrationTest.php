<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\EventEnvelope;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Config\SeoConfig;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Internal\Seo\LinkHealthChecker;
use Pulsar\Extension\Cms\Seo\Event\LinkHealthCheckCompleted;
use Pulsar\Extension\Cms\Seo\LinkHealthCheck;
use Pulsar\Extension\Cms\Seo\LinkHealthRepositoryInterface;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function in_array;

/**
 * Integration tests for the link health checker.
 *
 * Since LinkHealthChecker makes real HTTP requests via get_headers(),
 * these tests exercise the URL extraction, repository orchestration,
 * and orphan detection logic. Tests that require network access use
 * well-known URLs or validate behavior when requests fail.
 */
#[CoversClass(LinkHealthChecker::class)]
final class LinkHealthIntegrationTest extends TestCase
{
    private InMemoryLinkHealthContentRepository $contentRepo;
    private InMemoryLinkHealthTranslationRepository $translationRepo;
    private InMemoryLinkHealthRepository $linkHealthRepo;
    private RecordingLinkEventDispatcher $eventDispatcher;
    private CmsConfig $config;

    protected function setUp(): void
    {
        $this->contentRepo = new InMemoryLinkHealthContentRepository();
        $this->translationRepo = new InMemoryLinkHealthTranslationRepository();
        $this->linkHealthRepo = new InMemoryLinkHealthRepository();
        $this->eventDispatcher = new RecordingLinkEventDispatcher();

        $this->config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en'],
            seo: new SeoConfig(),
        );
    }

    // -- checkContent extracts URLs and creates records -----------------------

    #[Test]
    public function checkContentCreatesRecordsForEachUrl(): void
    {
        $content = $this->createPublishedContent('c-001');
        $this->contentRepo->add($content);

        $this->translationRepo->add($this->createTranslation(
            'c-001',
            'en',
            '<p>Visit <a href="https://httpbin.org/status/200">valid</a> and '
            . '<a href="https://httpbin.org/status/404">broken</a></p>',
        ));

        $checker = $this->createChecker();
        $results = $checker->checkContent($content, 'en');

        // Two URLs extracted, two records created
        self::assertCount(2, $results);
        self::assertCount(2, $this->linkHealthRepo->all());

        // Verify the target URLs were extracted correctly
        $urls = array_map(static fn(LinkHealthCheck $c) => $c->targetUrl, $results);
        self::assertContains('https://httpbin.org/status/200', $urls);
        self::assertContains('https://httpbin.org/status/404', $urls);
    }

    // -- checkContent skips relative URLs and non-HTTP schemes ----------------

    #[Test]
    public function checkContentOnlyExtractsHttpUrls(): void
    {
        $content = $this->createPublishedContent('c-002');
        $this->contentRepo->add($content);

        $this->translationRepo->add($this->createTranslation(
            'c-002',
            'en',
            '<p><a href="/relative/path">relative</a> '
            . '<a href="mailto:test@example.com">mail</a> '
            . '<a href="https://example.com/page">https</a> '
            . '<img src="http://example.com/image.png" /></p>',
        ));

        $checker = $this->createChecker();
        $results = $checker->checkContent($content, 'en');

        // Only http:// and https:// URLs should be extracted
        self::assertCount(2, $results);

        $urls = array_map(static fn(LinkHealthCheck $c) => $c->targetUrl, $results);
        self::assertContains('https://example.com/page', $urls);
        self::assertContains('http://example.com/image.png', $urls);
    }

    // -- checkContent deduplicates URLs ---------------------------------------

    #[Test]
    public function checkContentDeduplicatesSameUrl(): void
    {
        $content = $this->createPublishedContent('c-003');
        $this->contentRepo->add($content);

        $this->translationRepo->add($this->createTranslation(
            'c-003',
            'en',
            '<p><a href="https://example.com/page">first</a> '
            . '<a href="https://example.com/page">second</a> '
            . '<a href="https://example.com/other">other</a></p>',
        ));

        $checker = $this->createChecker();
        $results = $checker->checkContent($content, 'en');

        // Duplicate URL should be deduplicated
        self::assertCount(2, $results);
    }

    // -- checkContent returns empty for missing translation -------------------

    #[Test]
    public function checkContentReturnsEmptyForMissingTranslation(): void
    {
        $content = $this->createPublishedContent('c-004');
        $this->contentRepo->add($content);
        // No translation added

        $checker = $this->createChecker();
        $results = $checker->checkContent($content, 'en');

        self::assertSame([], $results);
    }

    // -- checkContent clears previous results before re-checking --------------

    #[Test]
    public function checkContentClearsPreviousResults(): void
    {
        $content = $this->createPublishedContent('c-005');
        $this->contentRepo->add($content);

        $this->translationRepo->add($this->createTranslation(
            'c-005',
            'en',
            '<a href="https://example.com/a">link</a>',
        ));

        $checker = $this->createChecker();

        // First check
        $checker->checkContent($content, 'en');
        self::assertCount(1, $this->linkHealthRepo->all());

        // Second check should clear and re-create
        $checker->checkContent($content, 'en');
        self::assertCount(1, $this->linkHealthRepo->all());
    }

    // -- checkAll dispatches event with totals --------------------------------

    #[Test]
    public function checkAllDispatchesCompletionEvent(): void
    {
        $content = $this->createPublishedContent('c-006');
        $this->contentRepo->add($content);

        $this->translationRepo->add($this->createTranslation(
            'c-006',
            'en',
            '<a href="https://example.com/one">one</a> '
            . '<a href="https://example.com/two">two</a>',
        ));

        $checker = $this->createChecker();
        $checker->checkAll();

        // Verify event was dispatched
        self::assertCount(1, $this->eventDispatcher->dispatched);
        $event = $this->eventDispatcher->dispatched[0];
        self::assertInstanceOf(LinkHealthCheckCompleted::class, $event);
        self::assertSame(2, $event->totalChecked);
    }

    // -- checkAll processes multiple content items ----------------------------

    #[Test]
    public function checkAllProcessesAllPublishedContent(): void
    {
        $contentA = $this->createPublishedContent('c-007a');
        $contentB = $this->createPublishedContent('c-007b');
        $this->contentRepo->add($contentA);
        $this->contentRepo->add($contentB);

        $this->translationRepo->add($this->createTranslation(
            'c-007a',
            'en',
            '<a href="https://example.com/a">link</a>',
        ));
        $this->translationRepo->add($this->createTranslation(
            'c-007b',
            'en',
            '<a href="https://example.com/b">link</a>',
        ));

        $checker = $this->createChecker();
        $results = $checker->checkAll();

        // Both content items processed
        self::assertCount(2, $results);

        $contentIds = array_map(static fn(LinkHealthCheck $c) => $c->sourceContentId, $results);
        self::assertContains('c-007a', $contentIds);
        self::assertContains('c-007b', $contentIds);
    }

    // -- getBrokenLinks delegates to repository -------------------------------

    #[Test]
    public function getBrokenLinksDelegatesToRepository(): void
    {
        $now = new DateTimeImmutable();

        // Seed a broken link record directly into the repository
        $brokenCheck = new LinkHealthCheck(
            id: 'check-001',
            tenantId: null,
            sourceContentId: 'c-010',
            sourceLocale: 'en',
            targetUrl: 'https://example.com/broken',
            isBroken: true,
            isRedirected: false,
            httpStatusCode: 404,
            lastCheckedAt: $now,
            createdAt: $now,
        );
        $this->linkHealthRepo->save($brokenCheck);

        // Seed a valid link record
        $validCheck = new LinkHealthCheck(
            id: 'check-002',
            tenantId: null,
            sourceContentId: 'c-010',
            sourceLocale: 'en',
            targetUrl: 'https://example.com/valid',
            isBroken: false,
            isRedirected: false,
            httpStatusCode: 200,
            lastCheckedAt: $now,
            createdAt: $now,
        );
        $this->linkHealthRepo->save($validCheck);

        $checker = $this->createChecker();
        $broken = $checker->getBrokenLinks();

        self::assertCount(1, $broken);
        self::assertSame('https://example.com/broken', $broken[0]->targetUrl);
        self::assertTrue($broken[0]->isBroken);
    }

    // -- getOrphanContent finds content with no inbound links -----------------

    #[Test]
    public function orphanDetectionFindsUnlinkedContent(): void
    {
        $now = new DateTimeImmutable();

        // Published content with inbound link health records
        $linked = $this->createPublishedContent('c-linked');
        $this->contentRepo->add($linked);

        // Seed a link health record pointing TO this content (makes it "linked")
        $this->linkHealthRepo->save(new LinkHealthCheck(
            id: 'check-inbound',
            tenantId: null,
            sourceContentId: 'c-linked',
            sourceLocale: 'en',
            targetUrl: 'https://example.com/linked',
            isBroken: false,
            isRedirected: false,
            httpStatusCode: 200,
            lastCheckedAt: $now,
            createdAt: $now,
        ));

        // Published content with NO inbound link health records (orphan)
        $orphan = $this->createPublishedContent('c-orphan');
        $this->contentRepo->add($orphan);

        $checker = $this->createChecker();
        $orphans = $checker->getOrphanContent();

        // Only the orphan content should be returned
        self::assertCount(1, $orphans);
        self::assertSame('c-orphan', $orphans[0]->id);
    }

    // -- Content with no URLs produces empty results --------------------------

    #[Test]
    public function contentWithNoUrlsProducesEmptyResults(): void
    {
        $content = $this->createPublishedContent('c-nolinks');
        $this->contentRepo->add($content);

        $this->translationRepo->add($this->createTranslation(
            'c-nolinks',
            'en',
            '<p>Just plain text with no links at all.</p>',
        ));

        $checker = $this->createChecker();
        $results = $checker->checkContent($content, 'en');

        self::assertSame([], $results);
    }

    // -- Helpers --------------------------------------------------------------

    private function createChecker(): LinkHealthChecker
    {
        return new LinkHealthChecker(
            $this->contentRepo,
            $this->translationRepo,
            $this->linkHealthRepo,
            $this->eventDispatcher,
            new NullLogger(),
            $this->config,
        );
    }

    private function createPublishedContent(string $id): Content
    {
        $now = new DateTimeImmutable();

        return new Content(
            id: $id,
            tenantId: null,
            contentType: ContentType::Article,
            authorId: 'author-001',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $now,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
            version: 1,
        );
    }

    private function createTranslation(string $contentId, string $locale, string $body): ContentTranslation
    {
        return new ContentTranslation(
            id: "trans-{$contentId}-{$locale}",
            contentId: $contentId,
            locale: $locale,
            title: 'Test Article',
            slugSegment: 'test',
            path: "{$locale}/test",
            body: $body,
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: '',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }
}

// -- In-memory repositories for link health integration tests -----------------

final class InMemoryLinkHealthContentRepository implements ContentRepositoryInterface
{
    /** @var array<string, Content> */
    private array $contents = [];

    public function add(Content $content): void
    {
        $this->contents[$content->id] = $content;
    }

    public function findById(string $id): ?Content
    {
        return $this->contents[$id] ?? null;
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
            static fn(Content $c) => $c->status === PublishingStatus::Published
                && $c->deletedAt === null
                && ($contentType === null || $c->contentType->value === $contentType),
        );

        $items = array_values($items);
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($items, $offset, $perPage);

        return new PaginationResult(
            items: $pageItems,
            total: $total,
            hasMore: ($offset + $perPage) < $total,
            perPage: $perPage,
        );
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
        return [];
    }

    public function save(Content $content): void
    {
        $this->contents[$content->id] = $content;
    }

    public function delete(Content $content): void
    {
        unset($this->contents[$content->id]);
    }

    public function findDescendants(string $contentId): array
    {
        return [];
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
}

final class InMemoryLinkHealthTranslationRepository implements ContentTranslationRepositoryInterface
{
    /** @var list<ContentTranslation> */
    private array $translations = [];

    public function add(ContentTranslation $translation): void
    {
        $this->translations[] = $translation;
    }

    public function findById(string $id): ?ContentTranslation
    {
        foreach ($this->translations as $t) {
            if ($t->id === $id) {
                return $t;
            }
        }

        return null;
    }

    public function findByContentId(string $contentId): array
    {
        return array_values(array_filter(
            $this->translations,
            static fn(ContentTranslation $t) => $t->contentId === $contentId,
        ));
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

    public function findByPath(string $locale, string $path, ?string $tenantId = null): ?ContentTranslation
    {
        return null;
    }

    public function save(ContentTranslation $translation): void
    {
        $this->translations[] = $translation;
    }

    public function delete(string $id): void {}
}

final class InMemoryLinkHealthRepository implements LinkHealthRepositoryInterface
{
    /** @var array<string, LinkHealthCheck> */
    private array $checks = [];

    public function findByContent(string $contentId, string $locale): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn(LinkHealthCheck $c) => $c->sourceContentId === $contentId && $c->sourceLocale === $locale,
        ));
    }

    public function findByContentIds(array $contentIds, string $locale): array
    {
        $grouped = [];

        foreach ($this->checks as $check) {
            if (in_array($check->sourceContentId, $contentIds, true) && $check->sourceLocale === $locale) {
                $grouped[$check->sourceContentId][] = $check;
            }
        }

        return $grouped;
    }

    public function findBroken(?string $tenantId = null, int $page = 1, int $perPage = 50): array
    {
        $broken = array_values(array_filter(
            $this->checks,
            static fn(LinkHealthCheck $c) => $c->isBroken,
        ));

        $offset = ($page - 1) * $perPage;

        return array_slice($broken, $offset, $perPage);
    }

    public function save(LinkHealthCheck $check): void
    {
        $this->checks[$check->id] = $check;
    }

    public function deleteByContent(string $contentId, string $locale): void
    {
        $this->checks = array_filter(
            $this->checks,
            static fn(LinkHealthCheck $c) => !($c->sourceContentId === $contentId && $c->sourceLocale === $locale),
        );
    }

    /** @return list<LinkHealthCheck> */
    public function all(): array
    {
        return array_values($this->checks);
    }
}

final class RecordingLinkEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }

    public function dispatchEnvelope(EventEnvelope $envelope): EventEnvelope
    {
        return $envelope;
    }
}
