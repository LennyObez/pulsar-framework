<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Http\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Http\Controller\ContentController;
use Pulsar\Extension\Cms\I18n\HreflangGenerator;
use Pulsar\Extension\Cms\Internal\Security\CmsKeyManager;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGeneratorInterface;
use Pulsar\I18n\Locale\UrlPrefixExtractor;
use Pulsar\Security\Crypto\MasterKey;
use ReflectionClass;

/**
 * Verifies that ContentController::show() correctly resolves locale from
 * both CMS-specific and core framework middleware attributes (blocker #2).
 */
final class ContentControllerLocaleTest extends TestCase
{
    #[Test]
    public function showUsesCorLocaleAttributeWhenCmsLocaleAbsent(): void
    {
        $config = CmsConfig::fromArray([
            'supported_locales' => ['en', 'fr', 'de'],
            'default_locale' => 'en',
        ]);

        $now = new DateTimeImmutable();

        $translation = new ContentTranslation(
            id: 'trans-1',
            contentId: 'content-1',
            locale: 'fr',
            title: 'Accueil',
            slugSegment: 'accueil',
            path: 'accueil',
            body: '<p>Bienvenue</p>',
            excerpt: null,
            metaTitle: 'Accueil',
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 1,
            bodyPlaintext: 'Bienvenue',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $content = new Content(
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

        // Translation repo: returns translation for 'fr' locale and 'accueil' path
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByPath')->willReturnCallback(
            static function (string $locale, string $path) use ($translation): ?ContentTranslation {
                if ($locale === 'fr' && $path === 'accueil') {
                    return $translation;
                }
                return null;
            },
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $blockRepo = $this->createStub(ContentBlockRepositoryInterface::class);
        $blockRepo->method('findByContentAndLocale')->willReturn([]);

        $redirectRepo = $this->createStub(RedirectRepositoryInterface::class);
        $redirectRepo->method('findByPath')->willReturn(null);

        $fieldRepo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $fieldRepo->method('findValues')->willReturn([]);

        $breadcrumbGen = $this->createStub(BreadcrumbGeneratorInterface::class);
        $breadcrumbGen->method('generate')->willReturn([]);

        $hreflangTranslationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $hreflangTranslationRepo->method('findByContentId')->willReturn([]);
        $hreflangGen = new HreflangGenerator($hreflangTranslationRepo, new UrlPrefixExtractor());

        // CmsKeyManager requires MasterKey; use newInstanceWithoutConstructor
        // since previewKey() is never called in these tests (no preview token)
        $keyManagerReflection = new ReflectionClass(CmsKeyManager::class);
        /** @var CmsKeyManager $keyManager */
        $keyManager = $keyManagerReflection->newInstanceWithoutConstructor();

        $safeHtmlReflection = new ReflectionClass(SafeHtmlPolicy::class);
        /** @var SafeHtmlPolicy $safeHtml */
        $safeHtml = $safeHtmlReflection->newInstanceWithoutConstructor();

        $controller = new ContentController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
            blockRepository: $blockRepo,
            redirectRepository: $redirectRepo,
            fieldRepository: $fieldRepo,
            breadcrumbGenerator: $breadcrumbGen,
            hreflangGenerator: $hreflangGen,
            keyManager: $keyManager,
            safeHtmlPolicy: $safeHtml,
            config: $config,
        );

        // Build a request with _locale attribute (core middleware) set to 'fr'
        // and no cms_locale attribute; path = 'accueil' (no locale prefix in URL)
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/accueil');
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('example.com');
        $uri->method('getPort')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturnCallback(
            static function (string $name): mixed {
                return match ($name) {
                    'cms_locale' => null,
                    '_locale' => 'fr',
                    'tenant_id' => null,
                    default => null,
                };
            },
        );
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match ($name) {
                'Accept' => 'application/json',
                default => '',
            },
        );
        $request->method('getQueryParams')->willReturn([]);

        $response = $controller->show($request);

        // Without the fix, this would return 404 because locale stayed 'en'
        // and no translation exists for path 'accueil' in locale 'en'
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function showPrefersExplicitCmsLocaleOverCoreLocale(): void
    {
        $config = CmsConfig::fromArray([
            'supported_locales' => ['en', 'fr', 'de'],
            'default_locale' => 'en',
        ]);

        $now = new DateTimeImmutable();

        $translation = new ContentTranslation(
            id: 'trans-2',
            contentId: 'content-2',
            locale: 'de',
            title: 'Startseite',
            slugSegment: 'startseite',
            path: 'startseite',
            body: '<p>Willkommen</p>',
            excerpt: null,
            metaTitle: 'Startseite',
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 1,
            bodyPlaintext: 'Willkommen',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $content = new Content(
            id: 'content-2',
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

        // Translation repo: only responds to 'de' locale
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByPath')->willReturnCallback(
            static function (string $locale, string $path) use ($translation): ?ContentTranslation {
                if ($locale === 'de' && $path === 'startseite') {
                    return $translation;
                }
                return null;
            },
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $blockRepo = $this->createStub(ContentBlockRepositoryInterface::class);
        $blockRepo->method('findByContentAndLocale')->willReturn([]);

        $redirectRepo = $this->createStub(RedirectRepositoryInterface::class);
        $redirectRepo->method('findByPath')->willReturn(null);

        $fieldRepo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $fieldRepo->method('findValues')->willReturn([]);

        $breadcrumbGen = $this->createStub(BreadcrumbGeneratorInterface::class);
        $breadcrumbGen->method('generate')->willReturn([]);

        $hreflangTranslationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $hreflangTranslationRepo->method('findByContentId')->willReturn([]);
        $hreflangGen = new HreflangGenerator($hreflangTranslationRepo, new UrlPrefixExtractor());

        // CmsKeyManager requires MasterKey; use newInstanceWithoutConstructor
        // since previewKey() is never called in these tests (no preview token)
        $keyManagerReflection = new ReflectionClass(CmsKeyManager::class);
        /** @var CmsKeyManager $keyManager */
        $keyManager = $keyManagerReflection->newInstanceWithoutConstructor();

        $safeHtmlReflection = new ReflectionClass(SafeHtmlPolicy::class);
        /** @var SafeHtmlPolicy $safeHtml */
        $safeHtml = $safeHtmlReflection->newInstanceWithoutConstructor();

        $controller = new ContentController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
            blockRepository: $blockRepo,
            redirectRepository: $redirectRepo,
            fieldRepository: $fieldRepo,
            breadcrumbGenerator: $breadcrumbGen,
            hreflangGenerator: $hreflangGen,
            keyManager: $keyManager,
            safeHtmlPolicy: $safeHtml,
            config: $config,
        );

        // Request has cms_locale=de AND _locale=fr. CMS locale must win.
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/de/startseite');
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('example.com');
        $uri->method('getPort')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturnCallback(
            static function (string $name): mixed {
                return match ($name) {
                    'cms_locale' => 'de',
                    '_locale' => 'fr',
                    'tenant_id' => null,
                    default => null,
                };
            },
        );
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match ($name) {
                'Accept' => 'application/json',
                default => '',
            },
        );
        $request->method('getQueryParams')->willReturn([]);

        $response = $controller->show($request);

        // cms_locale=de must win over _locale=fr
        self::assertSame(200, $response->getStatusCode());
    }
}
