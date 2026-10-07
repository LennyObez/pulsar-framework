<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentRevision;
use Pulsar\Extension\Cms\Content\ContentTranslation;

#[CoversClass(ContentRevision::class)]
final class ContentRevisionTest extends TestCase
{
    #[Test]
    public function fromTranslation_creates_revision_with_evidence_hash(): void
    {
        $translation = new ContentTranslation(
            id: 'trans-1',
            contentId: 'content-1',
            locale: 'en',
            title: 'My Article',
            slugSegment: 'my-article',
            path: 'my-article',
            body: '<p>Hello world</p>',
            excerpt: 'Short excerpt',
            metaTitle: 'SEO Title',
            metaDescription: 'SEO Description',
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Hello world',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $revision = ContentRevision::fromTranslation(
            id: 'rev-1',
            translation: $translation,
            revisionNumber: 1,
            authorId: 'author-1',
            reason: 'Initial draft',
        );

        self::assertSame('rev-1', $revision->id);
        self::assertSame('content-1', $revision->contentId);
        self::assertSame('en', $revision->locale);
        self::assertSame(1, $revision->revisionNumber);
        self::assertSame('My Article', $revision->title);
        self::assertSame('my-article', $revision->slug);
        self::assertSame('<p>Hello world</p>', $revision->body);
        self::assertSame('Short excerpt', $revision->excerpt);
        self::assertSame('SEO Title', $revision->metaTitle);
        self::assertSame('SEO Description', $revision->metaDescription);
        self::assertSame('author-1', $revision->authorId);
        self::assertSame('Initial draft', $revision->reason);
        self::assertNotEmpty($revision->evidenceHash);
    }

    #[Test]
    public function fromTranslation_without_reason(): void
    {
        $translation = new ContentTranslation(
            id: 'trans-1',
            contentId: 'content-1',
            locale: 'en',
            title: 'Title',
            slugSegment: 'slug',
            path: 'slug',
            body: 'Body',
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

        $revision = ContentRevision::fromTranslation('rev-1', $translation, 1, 'author-1');

        self::assertNull($revision->reason);
    }

    #[Test]
    public function computeEvidenceHash_is_deterministic(): void
    {
        $hash1 = ContentRevision::computeEvidenceHash(
            'Title',
            'slug',
            'Body',
            'Excerpt',
            'Meta Title',
            'Meta Desc',
        );
        $hash2 = ContentRevision::computeEvidenceHash(
            'Title',
            'slug',
            'Body',
            'Excerpt',
            'Meta Title',
            'Meta Desc',
        );

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function computeEvidenceHash_differs_for_different_content(): void
    {
        $hash1 = ContentRevision::computeEvidenceHash(
            'Title A',
            'slug-a',
            'Body A',
            null,
            null,
            null,
        );
        $hash2 = ContentRevision::computeEvidenceHash(
            'Title B',
            'slug-b',
            'Body B',
            null,
            null,
            null,
        );

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function computeEvidenceHash_handles_null_optional_fields(): void
    {
        $hash = ContentRevision::computeEvidenceHash(
            'Title',
            'slug',
            'Body',
            null,
            null,
            null,
        );

        self::assertNotEmpty($hash);
    }
}
