<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentRevision;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use ReflectionClass;

use function in_array;
use function strlen;

#[CoversClass(ContentRevision::class)]
final class ContentRevisionTest extends TestCase
{
    private const string CONTENT_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const string AUTHOR_ID = '01912345-6789-7abc-8def-0123456789cd';

    protected function setUp(): void
    {
        if (!in_array('blake2b', hash_algos(), true)) {
            self::markTestSkipped('blake2b hash algorithm is not available in this PHP build');
        }
    }

    #[Test]
    public function test_from_translation_creates_revision(): void
    {
        $translation = new ContentTranslation(
            id: 'tr-001',
            contentId: self::CONTENT_ID,
            locale: 'en',
            title: 'Test Article',
            slugSegment: 'test-article',
            path: 'test-article',
            body: '<p>Body content</p>',
            excerpt: 'An excerpt',
            metaTitle: 'Meta Title',
            metaDescription: 'Meta Description',
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 3,
            bodyPlaintext: 'Body content',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $revision = ContentRevision::fromTranslation(
            id: 'rev-001',
            translation: $translation,
            revisionNumber: 1,
            authorId: self::AUTHOR_ID,
            reason: 'Initial creation',
        );

        self::assertSame('rev-001', $revision->id);
        self::assertSame(self::CONTENT_ID, $revision->contentId);
        self::assertSame('en', $revision->locale);
        self::assertSame(1, $revision->revisionNumber);
        self::assertSame('Test Article', $revision->title);
        self::assertSame('test-article', $revision->slug);
        self::assertSame('<p>Body content</p>', $revision->body);
        self::assertSame('An excerpt', $revision->excerpt);
        self::assertSame('Meta Title', $revision->metaTitle);
        self::assertSame('Meta Description', $revision->metaDescription);
        self::assertSame(self::AUTHOR_ID, $revision->authorId);
        self::assertSame('Initial creation', $revision->reason);
        self::assertNotEmpty($revision->evidenceHash);
    }

    #[Test]
    public function test_from_translation_with_null_reason(): void
    {
        $translation = new ContentTranslation(
            id: 'tr-002',
            contentId: self::CONTENT_ID,
            locale: 'fr',
            title: 'Titre',
            slugSegment: 'titre',
            path: 'titre',
            body: '<p>Contenu</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Contenu',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $revision = ContentRevision::fromTranslation(
            id: 'rev-002',
            translation: $translation,
            revisionNumber: 2,
            authorId: self::AUTHOR_ID,
        );

        self::assertNull($revision->reason);
        self::assertNull($revision->excerpt);
        self::assertNull($revision->metaTitle);
        self::assertNull($revision->metaDescription);
    }

    // ── Evidence hash computation ────────────────────────────────────

    #[Test]
    public function test_compute_evidence_hash_returns_blake2b(): void
    {
        $hash = ContentRevision::computeEvidenceHash(
            'Title',
            'slug',
            '<p>Body</p>',
            'Excerpt',
            'Meta Title',
            'Meta Description',
        );

        self::assertNotEmpty($hash);
        self::assertSame(128, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]+$/', $hash);
    }

    #[Test]
    public function test_compute_evidence_hash_deterministic(): void
    {
        $hash1 = ContentRevision::computeEvidenceHash('T', 's', 'b', null, null, null);
        $hash2 = ContentRevision::computeEvidenceHash('T', 's', 'b', null, null, null);

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function test_compute_evidence_hash_changes_with_different_title(): void
    {
        $hash1 = ContentRevision::computeEvidenceHash('Title A', 's', 'b', null, null, null);
        $hash2 = ContentRevision::computeEvidenceHash('Title B', 's', 'b', null, null, null);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function test_compute_evidence_hash_changes_with_different_body(): void
    {
        $hash1 = ContentRevision::computeEvidenceHash('T', 's', '<p>A</p>', null, null, null);
        $hash2 = ContentRevision::computeEvidenceHash('T', 's', '<p>B</p>', null, null, null);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function test_from_translation_evidence_hash_matches_manual(): void
    {
        $translation = new ContentTranslation(
            id: 'tr-003',
            contentId: self::CONTENT_ID,
            locale: 'en',
            title: 'Title',
            slugSegment: 'title',
            path: 'title',
            body: '<p>Body</p>',
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

        $revision = ContentRevision::fromTranslation('rev-003', $translation, 1, self::AUTHOR_ID);

        $expectedHash = ContentRevision::computeEvidenceHash(
            'Title',
            'title',
            '<p>Body</p>',
            null,
            null,
            null,
        );

        self::assertSame($expectedHash, $revision->evidenceHash);
    }

    #[Test]
    public function test_content_revision_is_readonly(): void
    {
        $reflection = new ReflectionClass(ContentRevision::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
