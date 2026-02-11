<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\I18n\LocaleAwareContentQuery;

#[CoversClass(LocaleAwareContentQuery::class)]
final class LocaleAwareContentQueryTest extends TestCase
{
    private ContentTranslationRepositoryInterface & Stub $translationRepo;

    protected function setUp(): void
    {
        $this->translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
    }

    #[Test]
    public function findTranslationReturnsMatchingTranslation(): void
    {
        $translation = $this->buildTranslation('en', 'about');
        $this->translationRepo->method('findByContentAndLocale')->willReturn($translation);

        $query = new LocaleAwareContentQuery($this->translationRepo, new CmsConfig());
        $result = $query->findTranslation('c1', 'en');

        self::assertSame($translation, $result);
    }

    #[Test]
    public function findTranslationReturnsNullWhenNotFound(): void
    {
        $this->translationRepo->method('findByContentAndLocale')->willReturn(null);

        $query = new LocaleAwareContentQuery($this->translationRepo, new CmsConfig());
        $result = $query->findTranslation('c1', 'fr');

        self::assertNull($result);
    }

    #[Test]
    public function findTranslationFallsBackToDefaultLocale(): void
    {
        $enTranslation = $this->buildTranslation('en', 'about');

        $this->translationRepo->method('findByContentAndLocale')->willReturnCallback(
            static fn(string $contentId, string $locale): ?ContentTranslation =>
                $locale === 'en' ? $enTranslation : null,
        );

        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'fr']);
        $query = new LocaleAwareContentQuery($this->translationRepo, $config);

        $result = $query->findTranslation('c1', 'fr', fallbackToDefault: true);

        self::assertNotNull($result);
        self::assertSame('en', $result->locale);
    }

    #[Test]
    public function findTranslationDoesNotFallbackWhenAlreadyDefault(): void
    {
        $this->translationRepo->method('findByContentAndLocale')->willReturn(null);

        $config = new CmsConfig(defaultLocale: 'en');
        $query = new LocaleAwareContentQuery($this->translationRepo, $config);

        $result = $query->findTranslation('c1', 'en', fallbackToDefault: true);

        self::assertNull($result);
    }

    #[Test]
    public function findByPathReturnsTranslation(): void
    {
        $translation = $this->buildTranslation('en', 'about');
        $this->translationRepo->method('findByPath')->willReturn($translation);

        $query = new LocaleAwareContentQuery($this->translationRepo, new CmsConfig());
        $result = $query->findByPath('en', 'about');

        self::assertSame($translation, $result);
    }

    #[Test]
    public function findByPathFallsBackToDefaultLocale(): void
    {
        $enTranslation = $this->buildTranslation('en', 'about');

        $this->translationRepo->method('findByPath')->willReturnCallback(
            static fn(string $locale): ?ContentTranslation =>
                $locale === 'en' ? $enTranslation : null,
        );

        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'de']);
        $query = new LocaleAwareContentQuery($this->translationRepo, $config);

        $result = $query->findByPath('de', 'about', fallbackToDefault: true);

        self::assertNotNull($result);
        self::assertSame('en', $result->locale);
    }

    #[Test]
    public function allTranslationsIndexesByLocale(): void
    {
        $en = $this->buildTranslation('en', 'about');
        $fr = $this->buildTranslation('fr', 'a-propos');
        $this->translationRepo->method('findByContentId')->willReturn([$en, $fr]);

        $query = new LocaleAwareContentQuery($this->translationRepo, new CmsConfig());
        $result = $query->allTranslations('c1');

        self::assertCount(2, $result);
        self::assertSame($en, $result['en']);
        self::assertSame($fr, $result['fr']);
    }

    #[Test]
    public function localeAvailabilityReturnsBoolPerLocale(): void
    {
        $en = $this->buildTranslation('en', 'about');
        $this->translationRepo->method('findByContentId')->willReturn([$en]);

        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'fr', 'de']);
        $query = new LocaleAwareContentQuery($this->translationRepo, $config);

        $availability = $query->localeAvailability('c1');

        self::assertTrue($availability['en']);
        self::assertFalse($availability['fr']);
        self::assertFalse($availability['de']);
    }

    #[Test]
    public function translatedLocalesReturnsSupportedOnly(): void
    {
        $en = $this->buildTranslation('en', 'about');
        $ja = $this->buildTranslation('ja', 'about-ja'); // ja not in supported

        $this->translationRepo->method('findByContentId')->willReturn([$en, $ja]);

        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'fr']);
        $query = new LocaleAwareContentQuery($this->translationRepo, $config);

        $locales = $query->translatedLocales('c1');

        self::assertSame(['en'], $locales);
    }

    #[Test]
    public function missingLocalesReturnsUntranslatedSupported(): void
    {
        $en = $this->buildTranslation('en', 'about');
        $this->translationRepo->method('findByContentId')->willReturn([$en]);

        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'fr', 'de']);
        $query = new LocaleAwareContentQuery($this->translationRepo, $config);

        $missing = $query->missingLocales('c1');

        self::assertSame(['fr', 'de'], $missing);
    }

    #[Test]
    public function missingLocalesReturnsEmptyWhenAllTranslated(): void
    {
        $en = $this->buildTranslation('en', 'about');
        $fr = $this->buildTranslation('fr', 'a-propos');
        $this->translationRepo->method('findByContentId')->willReturn([$en, $fr]);

        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'fr']);
        $query = new LocaleAwareContentQuery($this->translationRepo, $config);

        $missing = $query->missingLocales('c1');

        self::assertSame([], $missing);
    }

    private function buildTranslation(string $locale, string $path): ContentTranslation
    {
        return new ContentTranslation(
            id: 'trans-' . $locale,
            contentId: 'content-1',
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
