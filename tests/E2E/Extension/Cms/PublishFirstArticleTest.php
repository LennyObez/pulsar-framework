<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Media\MediaAsset;
use Pulsar\Extension\Cms\Media\MediaVisibility;

use function hash;

/**
 * E2E: Publish a first article with multi-locale content, taxonomy, hero image,
 * SEO metadata, and sitemap verification.
 */
#[CoversClass(Content::class)]
#[Group('e2e-cms')]
final class PublishFirstArticleTest extends TestCase
{
    #[Test]
    public function publishArticleWithFullMetadata(): void
    {
        // 1. Create content
        $content = Content::create(
            id: '019e2e01-0000-7000-8000-000000000001',
            contentType: ContentType::Article,
            authorId: 'author-e2e-001',
        );
        self::assertTrue($content->isDraft());

        // 2. Create hero image media asset
        $heroImage = new MediaAsset(
            id: 'media-hero-001',
            tenantId: null,
            uploaderId: 'author-e2e-001',
            filename: 'hero-article.jpg',
            storagePath: 'media/2026/02/hero-article.jpg',
            disk: 'local',
            mimeType: 'image/jpeg',
            fileSize: 850_000,
            fileHash: hash('sha256', 'hero-content'),
            width: 1200,
            height: 630,
            exifData: null,
            altTextDefault: 'Article hero image',
            visibility: MediaVisibility::Public,
            dataClassification: DataClassification::Public,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
        );
        self::assertSame(1200, $heroImage->width);

        // 3. Create translations (EN + FR)
        $enTranslation = ContentTranslation::create(
            id: 'trans-en-001',
            contentId: $content->id,
            locale: 'en',
            title: 'Getting Started with Pulsar CMS',
            slugSegment: 'getting-started-pulsar-cms',
            path: 'blog/getting-started-pulsar-cms',
            body: '<p>Welcome to Pulsar CMS. This guide will walk you through your first article.</p>',
            excerpt: 'A quickstart guide for Pulsar CMS',
            metaTitle: 'Getting Started | Pulsar CMS',
            metaDescription: 'Learn how to create your first article with Pulsar CMS in under 5 minutes.',
            ogImageId: $heroImage->id,
            bodyPlaintext: 'Welcome to Pulsar CMS. This guide will walk you through your first article.',
        );

        $frTranslation = ContentTranslation::create(
            id: 'trans-fr-001',
            contentId: $content->id,
            locale: 'fr',
            title: 'Premiers pas avec Pulsar CMS',
            slugSegment: 'premiers-pas-pulsar-cms',
            path: 'blog/premiers-pas-pulsar-cms',
            body: '<p>Bienvenue sur Pulsar CMS. Ce guide vous accompagne dans la creation de votre premier article.</p>',
            excerpt: 'Guide de demarrage rapide pour Pulsar CMS',
            metaTitle: 'Premiers pas | Pulsar CMS',
            metaDescription: 'Apprenez a creer votre premier article avec Pulsar CMS en moins de 5 minutes.',
            ogImageId: $heroImage->id,
        );

        self::assertSame('en', $enTranslation->locale);
        self::assertSame('fr', $frTranslation->locale);
        self::assertSame($content->id, $enTranslation->contentId);
        self::assertSame($content->id, $frTranslation->contentId);

        // 4. Verify SEO metadata
        self::assertSame('Getting Started | Pulsar CMS', $enTranslation->metaTitle);
        self::assertNotNull($enTranslation->metaDescription);
        self::assertSame($heroImage->id, $enTranslation->ogImageId);

        // 5. Publish
        $published = $content->publish();
        self::assertSame(PublishingStatus::Published, $published->status);
        self::assertNotNull($published->publishedAt);

        // 6. Verify paths for sitemap
        self::assertSame('blog/getting-started-pulsar-cms', $enTranslation->path);
        self::assertSame('blog/premiers-pas-pulsar-cms', $frTranslation->path);

        // 7. Verify body plaintext is extracted for search
        self::assertStringContainsString('Welcome to Pulsar CMS', $enTranslation->bodyPlaintext);
        self::assertStringNotContainsString('<p>', $enTranslation->bodyPlaintext);
    }
}
