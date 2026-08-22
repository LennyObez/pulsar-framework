<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\I18n;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\I18n\LocaleSlugResolver;
use Pulsar\Extension\Cms\I18n\LocaleSlugResult;
use Pulsar\I18n\Locale\UrlPrefixExtractor;
use Pulsar\I18n\LocaleNegotiatorInterface;

#[CoversClass(LocaleSlugResolver::class)]
#[CoversClass(LocaleSlugResult::class)]
final class LocaleSlugResolverTest extends TestCase
{
    private UrlPrefixExtractor $extractor;
    private ContentTranslationRepositoryInterface & Stub $translationRepo;
    private RedirectRepositoryInterface & Stub $redirectRepo;
    private LocaleNegotiatorInterface & Stub $negotiator;

    protected function setUp(): void
    {
        $this->extractor = new UrlPrefixExtractor();
        $this->translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $this->redirectRepo = $this->createStub(RedirectRepositoryInterface::class);
        $this->negotiator = $this->createStub(LocaleNegotiatorInterface::class);
        $this->negotiator->method('negotiate')->willReturn('en');
    }

    #[Test]
    public function resolveFindsTranslationByLocalePrefixedPath(): void
    {
        $translation = $this->buildTranslation('fr', 'about');
        $this->translationRepo->method('findByPath')->willReturn($translation);
        $this->redirectRepo->method('findByPath')->willReturn(null);

        $resolver = $this->buildResolver();
        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'fr']);
        $request = $this->buildRequest('/fr/about');

        $result = $resolver->resolve($request, $config);

        self::assertNotNull($result);
        self::assertSame('fr', $result->locale);
        self::assertSame('about', $result->contentPath);
        self::assertTrue($result->hasTranslation());
        self::assertFalse($result->isRedirect());
        self::assertFalse($result->isFallback);
    }

    #[Test]
    public function resolveReturnsNullWhenNoMatch(): void
    {
        $this->translationRepo->method('findByPath')->willReturn(null);
        $this->redirectRepo->method('findByPath')->willReturn(null);

        $resolver = $this->buildResolver();
        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en']);
        $request = $this->buildRequest('/nonexistent');

        $result = $resolver->resolve($request, $config);

        self::assertNull($result);
    }

    #[Test]
    public function resolveReturnsRedirectWhenFound(): void
    {
        $redirect = $this->buildRedirect('/old-page', '/new-page');
        $this->redirectRepo->method('findByPath')->willReturn($redirect);

        $resolver = $this->buildResolver();
        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en']);
        $request = $this->buildRequest('/old-page');

        $result = $resolver->resolve($request, $config);

        self::assertNotNull($result);
        self::assertTrue($result->isRedirect());
        self::assertFalse($result->hasTranslation());
    }

    #[Test]
    public function resolveFallsBackToDefaultLocale(): void
    {
        $defaultTranslation = $this->buildTranslation('en', 'about');
        $this->translationRepo->method('findByPath')->willReturnCallback(
            static fn(string $locale): ?ContentTranslation => $locale === 'en' ? $defaultTranslation : null,
        );
        $this->redirectRepo->method('findByPath')->willReturn(null);

        $resolver = $this->buildResolver();
        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'fr']);
        $request = $this->buildRequest('/fr/about');

        $result = $resolver->resolve($request, $config, fallbackToDefault: true);

        self::assertNotNull($result);
        self::assertSame('en', $result->locale);
        self::assertTrue($result->isFallback);
    }

    #[Test]
    public function resolveByPathFindsTranslation(): void
    {
        $translation = $this->buildTranslation('en', 'docs/intro');
        $this->translationRepo->method('findByPath')->willReturn($translation);

        $resolver = $this->buildResolver();
        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en']);

        $result = $resolver->resolveByPath('en', 'docs/intro', $config);

        self::assertNotNull($result);
        self::assertSame('en', $result->locale);
        self::assertSame('docs/intro', $result->contentPath);
        self::assertFalse($result->isFallback);
    }

    #[Test]
    public function resolveByPathReturnsNullWhenNotFound(): void
    {
        $this->translationRepo->method('findByPath')->willReturn(null);

        $resolver = $this->buildResolver();
        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en']);

        $result = $resolver->resolveByPath('en', 'missing', $config);

        self::assertNull($result);
    }

    #[Test]
    public function resolveByPathFallsBackToDefault(): void
    {
        $defaultTranslation = $this->buildTranslation('en', 'page');
        $this->translationRepo->method('findByPath')->willReturnCallback(
            static fn(string $locale): ?ContentTranslation => $locale === 'en' ? $defaultTranslation : null,
        );

        $resolver = $this->buildResolver();
        $config = new CmsConfig(defaultLocale: 'en', supportedLocales: ['en', 'de']);

        $result = $resolver->resolveByPath('de', 'page', $config, fallbackToDefault: true);

        self::assertNotNull($result);
        self::assertTrue($result->isFallback);
        self::assertSame('en', $result->locale);
    }

    #[Test]
    public function availableLocalesReturnsTranslationLocales(): void
    {
        $this->translationRepo->method('findByContentId')->willReturn([
            $this->buildTranslation('en', 'about'),
            $this->buildTranslation('fr', 'a-propos'),
            $this->buildTranslation('de', 'ueber-uns'),
        ]);

        $resolver = $this->buildResolver();

        $locales = $resolver->availableLocales('content-1');

        self::assertSame(['en', 'fr', 'de'], $locales);
    }

    #[Test]
    public function availableLocalesReturnsEmptyForNoTranslations(): void
    {
        $this->translationRepo->method('findByContentId')->willReturn([]);

        $resolver = $this->buildResolver();

        self::assertSame([], $resolver->availableLocales('content-1'));
    }

    // --- LocaleSlugResult ---

    #[Test]
    public function localeSlugResultIsRedirect(): void
    {
        $redirect = $this->buildRedirect('/old-page', '/new-page');
        $result = new LocaleSlugResult('en', 'old-page', null, $redirect);

        self::assertTrue($result->isRedirect());
        self::assertFalse($result->hasTranslation());
    }

    #[Test]
    public function localeSlugResultHasTranslation(): void
    {
        $translation = $this->buildTranslation('en', 'page');
        $result = new LocaleSlugResult('en', 'page', $translation, null);

        self::assertFalse($result->isRedirect());
        self::assertTrue($result->hasTranslation());
    }

    private function buildResolver(): LocaleSlugResolver
    {
        return new LocaleSlugResolver(
            $this->extractor,
            $this->negotiator,
            $this->translationRepo,
            $this->redirectRepo,
        );
    }

    private function buildRequest(string $path): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }

    private function buildTranslation(string $locale, string $path): ContentTranslation
    {
        return new ContentTranslation(
            id: 'trans-' . $locale,
            contentId: 'content-1',
            locale: $locale,
            title: 'Title',
            slugSegment: 'slug',
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

    private function buildRedirect(string $from, string $to): Redirect
    {
        return new Redirect(
            id: 'redirect-1',
            tenantId: null,
            fromPath: $from,
            toPath: $to,
            statusCode: 301,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: new DateTimeImmutable(),
            createdBy: 'user-1',
            reason: 'Page moved',
        );
    }
}
