<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\I18n;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\I18n\HreflangGenerator;
use Pulsar\I18n\Locale\UrlPrefixExtractor;

#[CoversClass(HreflangGenerator::class)]
final class HreflangGeneratorTest extends TestCase
{
    #[Test]
    public function generateReturnsEmptyWhenNoTranslations(): void
    {
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentId')->willReturn([]);

        $generator = new HreflangGenerator($translationRepo, new UrlPrefixExtractor());
        $content = $this->buildContent();
        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
        );

        $links = $generator->generate($content, 'en', $config);

        self::assertSame([], $links);
    }

    #[Test]
    public function generateProducesLinksForAvailableTranslations(): void
    {
        $enTrans = $this->buildTranslation('en', '/about');
        $frTrans = $this->buildTranslation('fr', '/a-propos');

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentId')->willReturn([$enTrans, $frTrans]);

        $generator = new HreflangGenerator($translationRepo, new UrlPrefixExtractor());
        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
        );

        $links = $generator->generate($this->buildContent(), 'en', $config);

        // 2 locale links + 1 x-default = 3
        self::assertCount(3, $links);

        $locales = array_map(static fn($l) => $l->locale, $links);
        self::assertContains('en', $locales);
        self::assertContains('fr', $locales);
        self::assertContains('x-default', $locales);
    }

    #[Test]
    public function generateXDefaultPointsToDefaultLocale(): void
    {
        $enTrans = $this->buildTranslation('en', '/about');
        $frTrans = $this->buildTranslation('fr', '/a-propos');

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentId')->willReturn([$enTrans, $frTrans]);

        $generator = new HreflangGenerator($translationRepo, new UrlPrefixExtractor());
        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
            defaultLocaleInUrl: false,
        );

        $links = $generator->generate($this->buildContent(), 'fr', $config);

        $xDefault = null;
        $enLink = null;
        foreach ($links as $link) {
            if ($link->locale === 'x-default') {
                $xDefault = $link;
            }
            if ($link->locale === 'en') {
                $enLink = $link;
            }
        }

        self::assertNotNull($xDefault);
        self::assertNotNull($enLink);
        // x-default href should match the default locale (en) href
        self::assertSame($enLink->href, $xDefault->href);
    }

    #[Test]
    public function generateSkipsLocalesWithoutTranslation(): void
    {
        $enTrans = $this->buildTranslation('en', '/about');

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentId')->willReturn([$enTrans]);

        $generator = new HreflangGenerator($translationRepo, new UrlPrefixExtractor());
        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr', 'de'],
        );

        $links = $generator->generate($this->buildContent(), 'en', $config);

        // Only en + x-default (fr and de have no translations)
        self::assertCount(2, $links);
        $locales = array_map(static fn($l) => $l->locale, $links);
        self::assertContains('en', $locales);
        self::assertContains('x-default', $locales);
        self::assertNotContains('fr', $locales);
    }

    #[Test]
    public function generatePrefixesPathWithLeadingSlash(): void
    {
        $trans = $this->buildTranslation('en', 'no-slash-path');

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentId')->willReturn([$trans]);

        $generator = new HreflangGenerator($translationRepo, new UrlPrefixExtractor());
        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en'],
        );

        $links = $generator->generate($this->buildContent(), 'en', $config);

        // The href should have a leading slash
        $enLink = $links[0];
        self::assertStringStartsWith('/', $enLink->href);
    }

    private function buildContent(): Content
    {
        $now = new DateTimeImmutable();

        return new Content(
            id: 'content-1',
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'author-1',
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
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
        );
    }

    private function buildTranslation(string $locale, string $path): ContentTranslation
    {
        return new ContentTranslation(
            id: 'trans-' . $locale,
            contentId: 'content-1',
            locale: $locale,
            title: 'Title ' . $locale,
            slugSegment: 'about',
            path: $path,
            body: '<p>Body</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Body',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }
}
