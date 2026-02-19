<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\I18n\CmsLocaleUrlResolver;
use Pulsar\I18n\Locale\UrlPrefixExtractor;

use function md5;
use function substr;

#[CoversClass(CmsLocaleUrlResolver::class)]
final class CmsLocaleUrlResolverTest extends TestCase
{
    private UrlPrefixExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new UrlPrefixExtractor();
    }

    #[Test]
    public function returns_prefix_swapped_paths_when_no_content_id(): void
    {
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);

        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr', 'de'],
            defaultLocaleInUrl: false,
        );

        $resolver = new CmsLocaleUrlResolver(
            $translationRepo,
            $this->extractor,
            $config,
            currentContentId: null,
        );

        $alternates = $resolver->resolveAlternates('/about', 'en');

        self::assertCount(3, $alternates);
        self::assertSame('/about', $alternates['en']);
        self::assertSame('/fr/about', $alternates['fr']);
        self::assertSame('/de/about', $alternates['de']);
    }

    #[Test]
    public function returns_translated_paths_from_repository_when_content_id_set(): void
    {
        $contentId = '019577a0-0000-7000-8000-000000000001';

        $translations = [
            $this->createTranslation($contentId, 'en', 'about-us'),
            $this->createTranslation($contentId, 'fr', 'a-propos'),
            $this->createTranslation($contentId, 'de', 'ueber-uns'),
        ];

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentId')->willReturn($translations);

        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr', 'de'],
            defaultLocaleInUrl: false,
        );

        $resolver = new CmsLocaleUrlResolver(
            $translationRepo,
            $this->extractor,
            $config,
            currentContentId: $contentId,
        );

        $alternates = $resolver->resolveAlternates('/about-us', 'en');

        self::assertCount(3, $alternates);
        self::assertSame('/about-us', $alternates['en']);
        self::assertSame('/fr/a-propos', $alternates['fr']);
        self::assertSame('/de/ueber-uns', $alternates['de']);
    }

    #[Test]
    public function filters_to_supported_locales_only(): void
    {
        $contentId = '019577a0-0000-7000-8000-000000000001';

        $translations = [
            $this->createTranslation($contentId, 'en', 'about-us'),
            $this->createTranslation($contentId, 'fr', 'a-propos'),
            $this->createTranslation($contentId, 'ja', 'watashitachi-ni-tsuite'),
        ];

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentId')->willReturn($translations);

        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
            defaultLocaleInUrl: false,
        );

        $resolver = new CmsLocaleUrlResolver(
            $translationRepo,
            $this->extractor,
            $config,
            currentContentId: $contentId,
        );

        $alternates = $resolver->resolveAlternates('/about-us', 'en');

        self::assertCount(2, $alternates);
        self::assertArrayHasKey('en', $alternates);
        self::assertArrayHasKey('fr', $alternates);
        self::assertArrayNotHasKey('ja', $alternates);
    }

    #[Test]
    public function handles_empty_translations(): void
    {
        $contentId = '019577a0-0000-7000-8000-000000000001';

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentId')->willReturn([]);

        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
            defaultLocaleInUrl: false,
        );

        $resolver = new CmsLocaleUrlResolver(
            $translationRepo,
            $this->extractor,
            $config,
            currentContentId: $contentId,
        );

        $alternates = $resolver->resolveAlternates('/about', 'en');

        self::assertSame([], $alternates);
    }

    #[Test]
    public function withContentId_returns_new_instance_with_content_context(): void
    {
        $contentId = '019577a0-0000-7000-8000-000000000001';

        $translations = [
            $this->createTranslation($contentId, 'en', 'about-us'),
            $this->createTranslation($contentId, 'fr', 'a-propos'),
        ];

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentId')->willReturn($translations);

        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
            defaultLocaleInUrl: false,
        );

        // Start without content ID (singleton state)
        $resolver = new CmsLocaleUrlResolver(
            $translationRepo,
            $this->extractor,
            $config,
        );

        // Without content ID, falls back to prefix swapping
        $alternatesWithout = $resolver->resolveAlternates('/about-us', 'en');
        self::assertSame('/about-us', $alternatesWithout['en']);
        self::assertSame('/fr/about-us', $alternatesWithout['fr']);

        // Use withContentId() to get a copy with content context
        $resolverWithContent = $resolver->withContentId($contentId);

        // The copy resolves translated slugs
        $alternatesWith = $resolverWithContent->resolveAlternates('/about-us', 'en');
        self::assertSame('/about-us', $alternatesWith['en']);
        self::assertSame('/fr/a-propos', $alternatesWith['fr']);

        // Original resolver is unchanged (still no content ID)
        $alternatesOriginal = $resolver->resolveAlternates('/about-us', 'en');
        self::assertSame('/fr/about-us', $alternatesOriginal['fr']);
    }

    #[Test]
    public function respects_default_locale_in_url_setting(): void
    {
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);

        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
            defaultLocaleInUrl: true,
        );

        $resolver = new CmsLocaleUrlResolver(
            $translationRepo,
            $this->extractor,
            $config,
            currentContentId: null,
        );

        $alternates = $resolver->resolveAlternates('/about', 'en');

        self::assertCount(2, $alternates);
        self::assertSame('/en/about', $alternates['en']);
        self::assertSame('/fr/about', $alternates['fr']);
    }

    private function createTranslation(string $contentId, string $locale, string $path): ContentTranslation
    {
        return new ContentTranslation(
            id: '019577a0-0000-7000-8000-' . substr(md5($locale), 0, 12),
            contentId: $contentId,
            locale: $locale,
            title: 'Title ' . $locale,
            slugSegment: $path,
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
