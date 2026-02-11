<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentRevision;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\FieldDiff;
use Pulsar\Extension\Cms\Content\RevisionDiff;
use Pulsar\Extension\Cms\Content\RevisionService;
use Pulsar\Extension\Cms\Exception\CmsException;

use function in_array;

#[CoversClass(RevisionService::class)]
#[CoversClass(RevisionDiff::class)]
#[CoversClass(FieldDiff::class)]
final class RevisionServiceTest extends TestCase
{
    private InMemoryRevisionRepository $revisionRepo;
    private InMemoryTranslationRepository $translationRepo;
    private RevisionService $service;

    protected function setUp(): void
    {
        $this->revisionRepo = new InMemoryRevisionRepository();
        $this->translationRepo = new InMemoryTranslationRepository();
        $this->service = new RevisionService($this->revisionRepo, $this->translationRepo);
    }

    #[Test]
    public function createRevisionReturnsRevisionWithCorrectFields(): void
    {
        $revision = $this->service->createRevision(
            contentId: 'content-01',
            locale: 'en',
            authorId: 'author-01',
            reason: 'Initial draft',
            title: 'Test Title',
            slug: 'test-title',
            body: '<p>Body</p>',
            excerpt: 'Short summary',
            metaTitle: 'SEO Title',
            metaDescription: 'SEO description',
        );

        self::assertSame('content-01', $revision->contentId);
        self::assertSame('en', $revision->locale);
        self::assertSame(1, $revision->revisionNumber);
        self::assertSame('Test Title', $revision->title);
        self::assertSame('test-title', $revision->slug);
        self::assertSame('<p>Body</p>', $revision->body);
        self::assertSame('Short summary', $revision->excerpt);
        self::assertSame('SEO Title', $revision->metaTitle);
        self::assertSame('SEO description', $revision->metaDescription);
        self::assertSame('author-01', $revision->authorId);
        self::assertSame('Initial draft', $revision->reason);
        self::assertNotEmpty($revision->evidenceHash);
        self::assertNotEmpty($revision->id);
    }

    #[Test]
    public function createRevisionIncrementsRevisionNumber(): void
    {
        $this->service->createRevision('c1', 'en', 'a1', null, 'T1', 's1', 'B1', null, null, null);
        $rev2 = $this->service->createRevision('c1', 'en', 'a1', null, 'T2', 's2', 'B2', null, null, null);

        self::assertSame(2, $rev2->revisionNumber);
    }

    #[Test]
    public function createRevisionFromTranslation(): void
    {
        $translation = new ContentTranslation(
            id: 'trans-01',
            contentId: 'content-01',
            locale: 'en',
            title: 'Translated Title',
            slugSegment: 'translated-title',
            path: 'translated-title',
            body: '<p>Translated body</p>',
            excerpt: 'Translated excerpt',
            metaTitle: 'Trans Meta',
            metaDescription: 'Trans Meta Desc',
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 3,
            bodyPlaintext: 'Translated body',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $revision = $this->service->createRevisionFromTranslation($translation, 'author-01', 'From translation');

        self::assertSame('content-01', $revision->contentId);
        self::assertSame('en', $revision->locale);
        self::assertSame('Translated Title', $revision->title);
        self::assertSame('translated-title', $revision->slug);
        self::assertSame('<p>Translated body</p>', $revision->body);
        self::assertSame('Translated excerpt', $revision->excerpt);
        self::assertSame('From translation', $revision->reason);
    }

    #[Test]
    public function getRevisionsReturnsListFromRepository(): void
    {
        $this->service->createRevision('c1', 'en', 'a1', null, 'T1', 's1', 'B1', null, null, null);
        $this->service->createRevision('c1', 'en', 'a1', null, 'T2', 's2', 'B2', null, null, null);
        $this->service->createRevision('c1', 'fr', 'a1', null, 'T3', 's3', 'B3', null, null, null);

        $enRevisions = $this->service->getRevisions('c1', 'en');
        self::assertCount(2, $enRevisions);

        $frRevisions = $this->service->getRevisions('c1', 'fr');
        self::assertCount(1, $frRevisions);
    }

    #[Test]
    public function restoreRevisionThrowsWhenRevisionNotFound(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('not found');

        $this->service->restoreRevision('nonexistent', 'author-01', 'restore reason');
    }

    #[Test]
    public function restoreRevisionThrowsWhenTranslationNotFound(): void
    {
        $revision = $this->service->createRevision('c1', 'en', 'a1', null, 'T', 's', 'B', null, null, null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Translation not found');

        $this->service->restoreRevision($revision->id, 'author-01', 'restore reason');
    }

    #[Test]
    public function restoreRevisionUpdatesTranslationAndCreatesRevisions(): void
    {
        $translation = new ContentTranslation(
            id: 'trans-01',
            contentId: 'c1',
            locale: 'en',
            title: 'Current Title',
            slugSegment: 'current-title',
            path: 'current-title',
            body: '<p>Current body</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Current body',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
        $this->translationRepo->save($translation);

        $oldRevision = $this->service->createRevision('c1', 'en', 'a1', null, 'Old Title', 'old-title', '<p>Old body</p>', 'Old excerpt', null, null);

        $restored = $this->service->restoreRevision($oldRevision->id, 'a2', 'Rolling back');

        self::assertSame('Old Title', $restored->title);
        self::assertSame('old-title', $restored->slugSegment);
        self::assertSame('<p>Old body</p>', $restored->body);
        self::assertSame('Old excerpt', $restored->excerpt);

        // Should have created: 1 initial revision + 1 auto-capture before restore + 1 restoration record = 3 total
        $allRevisions = $this->service->getRevisions('c1', 'en');
        self::assertCount(3, $allRevisions);
    }

    #[Test]
    public function computeDiffReturnsChangedFields(): void
    {
        $rev1 = $this->service->createRevision('c1', 'en', 'a1', null, 'Title A', 'slug-a', 'Body A', null, null, null);
        $rev2 = $this->service->createRevision('c1', 'en', 'a1', null, 'Title B', 'slug-a', 'Body B', 'excerpt', null, null);

        $diff = $this->service->computeDiff($rev1->id, $rev2->id);

        self::assertTrue($diff->hasChanges());
        self::assertSame($rev1->id, $diff->fromRevisionId);
        self::assertSame($rev2->id, $diff->toRevisionId);

        $changedFields = array_map(static fn(FieldDiff $f): string => $f->field, $diff->changes);
        self::assertContains('title', $changedFields);
        self::assertContains('body', $changedFields);
        self::assertContains('excerpt', $changedFields);
        self::assertNotContains('slug', $changedFields);
    }

    #[Test]
    public function computeDiffThrowsWhenRevisionNotFound(): void
    {
        $rev1 = $this->service->createRevision('c1', 'en', 'a1', null, 'T', 's', 'B', null, null, null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('not found');

        $this->service->computeDiff($rev1->id, 'nonexistent');
    }

    #[Test]
    public function computeDiffReturnsEmptyChangesWhenIdentical(): void
    {
        $rev1 = $this->service->createRevision('c1', 'en', 'a1', null, 'T', 's', 'B', null, null, null);
        $rev2 = $this->service->createRevision('c1', 'en', 'a1', null, 'T', 's', 'B', null, null, null);

        $diff = $this->service->computeDiff($rev1->id, $rev2->id);

        self::assertFalse($diff->hasChanges());
        self::assertSame([], $diff->changes);
    }
}

/**
 * @internal Test-only in-memory implementation
 */
final class InMemoryRevisionRepository implements ContentRevisionRepositoryInterface
{
    /** @var array<string, ContentRevision> */
    private array $revisions = [];

    public function findById(string $id): ?ContentRevision
    {
        return $this->revisions[$id] ?? null;
    }

    public function findByContentAndLocale(string $contentId, string $locale): array
    {
        return array_values(array_filter(
            $this->revisions,
            static fn(ContentRevision $r): bool => $r->contentId === $contentId && $r->locale === $locale,
        ));
    }

    public function getLatestRevisionNumber(string $contentId, string $locale): int
    {
        $max = 0;

        foreach ($this->revisions as $r) {
            if ($r->contentId === $contentId && $r->locale === $locale && $r->revisionNumber > $max) {
                $max = $r->revisionNumber;
            }
        }

        return $max;
    }

    public function save(ContentRevision $revision): void
    {
        $this->revisions[$revision->id] = $revision;
    }
}

/**
 * @internal Test-only in-memory implementation
 */
final class InMemoryTranslationRepository implements ContentTranslationRepositoryInterface
{
    /** @var array<string, ContentTranslation> */
    private array $translations = [];

    public function findById(string $id): ?ContentTranslation
    {
        return $this->translations[$id] ?? null;
    }

    public function findByContentId(string $contentId): array
    {
        return array_values(array_filter(
            $this->translations,
            static fn(ContentTranslation $t): bool => $t->contentId === $contentId,
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
        $result = [];

        foreach ($this->translations as $t) {
            if (in_array($t->contentId, $contentIds, true)) {
                $result[$t->contentId][] = $t;
            }
        }

        return $result;
    }

    public function save(ContentTranslation $translation): void
    {
        $this->translations[$translation->id] = $translation;
    }

    public function delete(string $id): void
    {
        unset($this->translations[$id]);
    }
}
