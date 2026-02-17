<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentRevision;

#[CoversClass(ContentRevision::class)]
final class ContentRevisionEvidenceTest extends TestCase
{
    #[Test]
    public function computeEvidenceHashReturnsDeterministicHex(): void
    {
        $hash1 = ContentRevision::computeEvidenceHash(
            'Title',
            'slug',
            'Body text',
            null,
            null,
            null,
        );

        $hash2 = ContentRevision::computeEvidenceHash(
            'Title',
            'slug',
            'Body text',
            null,
            null,
            null,
        );

        self::assertSame($hash1, $hash2);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hash1);
    }

    #[Test]
    public function computeEvidenceHashChangesWithDifferentInput(): void
    {
        $hash1 = ContentRevision::computeEvidenceHash('A', 'a', 'body', null, null, null);
        $hash2 = ContentRevision::computeEvidenceHash('B', 'b', 'body', null, null, null);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function computeEvidenceHashIncludesOptionalFields(): void
    {
        $withoutMeta = ContentRevision::computeEvidenceHash('T', 's', 'B', null, null, null);
        $withMeta = ContentRevision::computeEvidenceHash('T', 's', 'B', 'excerpt', 'Meta', 'Desc');

        self::assertNotSame($withoutMeta, $withMeta);
    }

    #[Test]
    public function constructSetsAllProperties(): void
    {
        $now = new DateTimeImmutable('2026-01-15T12:00:00Z');

        $revision = new ContentRevision(
            id: 'rev-001',
            contentId: 'content-001',
            locale: 'en',
            revisionNumber: 3,
            title: 'Page Title',
            slug: 'page-title',
            body: 'Body content',
            excerpt: 'Short excerpt',
            metaTitle: 'SEO Title',
            metaDescription: 'SEO Description',
            authorId: 'user-001',
            reason: 'Fixed typo',
            evidenceHash: 'abc123',
            createdAt: $now,
        );

        self::assertSame('rev-001', $revision->id);
        self::assertSame('content-001', $revision->contentId);
        self::assertSame('en', $revision->locale);
        self::assertSame(3, $revision->revisionNumber);
        self::assertSame('Page Title', $revision->title);
        self::assertSame('page-title', $revision->slug);
        self::assertSame('Body content', $revision->body);
        self::assertSame('Short excerpt', $revision->excerpt);
        self::assertSame('SEO Title', $revision->metaTitle);
        self::assertSame('SEO Description', $revision->metaDescription);
        self::assertSame('user-001', $revision->authorId);
        self::assertSame('Fixed typo', $revision->reason);
        self::assertSame('abc123', $revision->evidenceHash);
        self::assertSame($now, $revision->createdAt);
    }
}
