<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentRevision;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;

use function in_array;
use function strlen;

#[CoversClass(ContentRevision::class)]
final class RevisionSystemTest extends TestCase
{
    private ContentRevisionRepositoryInterface $revisionRepo;

    protected function setUp(): void
    {
        if (!in_array('blake2b', hash_algos(), true)) {
            self::markTestSkipped('blake2b hash algorithm is not available in this PHP build');
        }

        $this->revisionRepo = new InMemoryRevisionRepository();
    }

    #[Test]
    public function createRevisionOnContentUpdate(): void
    {
        $translation = ContentTranslation::create(
            id: 'trans-001',
            contentId: 'content-001',
            locale: 'en',
            title: 'Original Title',
            slugSegment: 'original-title',
            path: 'original-title',
            body: '<p>Original body</p>',
            excerpt: 'An excerpt',
            metaTitle: 'Original Meta',
            metaDescription: 'Original description',
        );

        $revisionNumber = $this->revisionRepo->getLatestRevisionNumber('content-001', 'en') + 1;
        self::assertSame(1, $revisionNumber);

        $revision = ContentRevision::fromTranslation(
            id: 'rev-001',
            translation: $translation,
            revisionNumber: $revisionNumber,
            authorId: 'author-001',
            reason: 'Initial creation',
        );

        $this->revisionRepo->save($revision);

        $found = $this->revisionRepo->findById('rev-001');

        self::assertNotNull($found);
        self::assertSame('content-001', $found->contentId);
        self::assertSame('en', $found->locale);
        self::assertSame(1, $found->revisionNumber);
        self::assertSame('Original Title', $found->title);
        self::assertSame('original-title', $found->slug);
        self::assertSame('<p>Original body</p>', $found->body);
        self::assertSame('An excerpt', $found->excerpt);
        self::assertSame('Original Meta', $found->metaTitle);
        self::assertSame('Original description', $found->metaDescription);
        self::assertSame('author-001', $found->authorId);
        self::assertSame('Initial creation', $found->reason);
    }

    #[Test]
    public function revisionEvidenceHashIsCorrect(): void
    {
        $translation = ContentTranslation::create(
            id: 'trans-002',
            contentId: 'content-002',
            locale: 'en',
            title: 'Test Title',
            slugSegment: 'test-title',
            path: 'test-title',
            body: '<p>Test body</p>',
        );

        $revision = ContentRevision::fromTranslation(
            id: 'rev-002',
            translation: $translation,
            revisionNumber: 1,
            authorId: 'author-001',
        );

        // Recompute the hash independently
        $expectedHash = ContentRevision::computeEvidenceHash(
            'Test Title',
            'test-title',
            '<p>Test body</p>',
            null,
            null,
            null,
        );

        self::assertSame($expectedHash, $revision->evidenceHash);
        self::assertNotEmpty($revision->evidenceHash);
        self::assertSame(128, strlen($revision->evidenceHash)); // BLAKE2b outputs 64 bytes = 128 hex chars
    }

    #[Test]
    public function restoreRevisionCreatesNewRevisionAndUpdatesContent(): void
    {
        // Create initial revision
        $originalTranslation = ContentTranslation::create(
            id: 'trans-003',
            contentId: 'content-003',
            locale: 'en',
            title: 'Version 1',
            slugSegment: 'version-1',
            path: 'version-1',
            body: '<p>Version 1 body</p>',
        );

        $rev1 = ContentRevision::fromTranslation(
            id: 'rev-003',
            translation: $originalTranslation,
            revisionNumber: 1,
            authorId: 'author-001',
            reason: 'Initial version',
        );
        $this->revisionRepo->save($rev1);

        // Create a second revision (content was updated)
        $updatedTranslation = ContentTranslation::create(
            id: 'trans-003',
            contentId: 'content-003',
            locale: 'en',
            title: 'Version 2',
            slugSegment: 'version-2',
            path: 'version-2',
            body: '<p>Version 2 body</p>',
        );

        $rev2 = ContentRevision::fromTranslation(
            id: 'rev-004',
            translation: $updatedTranslation,
            revisionNumber: 2,
            authorId: 'author-001',
            reason: 'Updated content',
        );
        $this->revisionRepo->save($rev2);

        // "Restore" revision 1 by creating a new revision with its data
        $restoredTranslation = ContentTranslation::create(
            id: 'trans-003',
            contentId: 'content-003',
            locale: 'en',
            title: $rev1->title,
            slugSegment: $rev1->slug,
            path: $rev1->slug,
            body: $rev1->body,
            excerpt: $rev1->excerpt,
            metaTitle: $rev1->metaTitle,
            metaDescription: $rev1->metaDescription,
        );

        $latestNumber = $this->revisionRepo->getLatestRevisionNumber('content-003', 'en');
        self::assertSame(2, $latestNumber);

        $rev3 = ContentRevision::fromTranslation(
            id: 'rev-005',
            translation: $restoredTranslation,
            revisionNumber: $latestNumber + 1,
            authorId: 'author-001',
            reason: 'Restored from revision #1',
        );
        $this->revisionRepo->save($rev3);

        self::assertSame(3, $rev3->revisionNumber);
        self::assertSame('Version 1', $rev3->title);
        self::assertSame('Restored from revision #1', $rev3->reason);

        // Evidence hashes of rev1 and rev3 should match (same content)
        self::assertSame($rev1->evidenceHash, $rev3->evidenceHash);
    }

    #[Test]
    public function revisionNumberAutoIncrements(): void
    {
        $translation = ContentTranslation::create(
            id: 'trans-004',
            contentId: 'content-004',
            locale: 'en',
            title: 'Test',
            slugSegment: 'test',
            path: 'test',
            body: '<p>Test</p>',
        );

        for ($i = 1; $i <= 5; $i++) {
            $revNumber = $this->revisionRepo->getLatestRevisionNumber('content-004', 'en') + 1;
            self::assertSame($i, $revNumber);

            $revision = ContentRevision::fromTranslation(
                id: "rev-auto-{$i}",
                translation: $translation,
                revisionNumber: $revNumber,
                authorId: 'author-001',
            );
            $this->revisionRepo->save($revision);
        }

        $allRevisions = $this->revisionRepo->findByContentAndLocale('content-004', 'en');
        self::assertCount(5, $allRevisions);

        // Verify they're ordered descending by revision number
        self::assertSame(5, $allRevisions[0]->revisionNumber);
        self::assertSame(1, $allRevisions[4]->revisionNumber);
    }
}

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
        $matching = array_values(array_filter(
            $this->revisions,
            static fn(ContentRevision $r) => $r->contentId === $contentId && $r->locale === $locale,
        ));

        // Sort descending by revision number
        usort($matching, static fn(ContentRevision $a, ContentRevision $b) => $b->revisionNumber <=> $a->revisionNumber);

        return $matching;
    }

    public function getLatestRevisionNumber(string $contentId, string $locale): int
    {
        $max = 0;

        foreach ($this->revisions as $revision) {
            if ($revision->contentId === $contentId && $revision->locale === $locale) {
                $max = max($max, $revision->revisionNumber);
            }
        }

        return $max;
    }

    public function save(ContentRevision $revision): void
    {
        $this->revisions[$revision->id] = $revision;
    }
}
