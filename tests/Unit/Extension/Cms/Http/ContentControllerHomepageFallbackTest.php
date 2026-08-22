<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
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
use Pulsar\Http\Message\ServerRequest;
use Pulsar\I18n\Locale\UrlPrefixExtractor;
use Pulsar\Security\Crypto\MasterKey;

use function str_repeat;

/**
 * Tests that the ContentController serves homepage content correctly,
 * querying by empty path as a fallback when no homepage_content_id is
 * configured. The welcome page only appears when no content exists at all.
 */
#[CoversClass(ContentController::class)]
final class ContentControllerHomepageFallbackTest extends TestCase
{
    private string $contentId = '019577a0-0000-7000-8000-000000000001';
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable();
    }

    private function createController(
        ContentRepositoryInterface $contentRepo,
        ContentTranslationRepositoryInterface $translationRepo,
        ?string $homepageContentId = null,
    ): ContentController {
        $keyManager = new CmsKeyManager(MasterKey::fromHex(str_repeat('ab', 32)));
        $config = CmsConfig::fromArray([
            'homepage_content_id' => $homepageContentId,
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ]);

        $redirectRepo = $this->createStub(RedirectRepositoryInterface::class);
        $redirectRepo->method('findByPath')->willReturn(null);

        $breadcrumbs = $this->createStub(BreadcrumbGeneratorInterface::class);
        $breadcrumbs->method('generate')->willReturn([]);

        $hreflang = new HreflangGenerator(
            $translationRepo,
            new UrlPrefixExtractor(),
        );

        $safeHtmlPolicy = new SafeHtmlPolicy(
            $this->createStub(AuditLoggerInterface::class),
        );

        return new ContentController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
            blockRepository: $this->createStub(ContentBlockRepositoryInterface::class),
            redirectRepository: $redirectRepo,
            fieldRepository: $this->createStub(FieldRegistryRepositoryInterface::class),
            breadcrumbGenerator: $breadcrumbs,
            hreflangGenerator: $hreflang,
            keyManager: $keyManager,
            safeHtmlPolicy: $safeHtmlPolicy,
            config: $config,
        );
    }

    private function createPublishedContent(): Content
    {
        return new Content(
            id: $this->contentId,
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'user-1',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $this->now,
            createdAt: $this->now,
            updatedAt: $this->now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
        );
    }

    private function createTranslation(string $path = ''): ContentTranslation
    {
        return new ContentTranslation(
            id: '019577a0-0000-7000-8000-000000000002',
            contentId: $this->contentId,
            locale: 'en',
            title: 'Home Page',
            slugSegment: '',
            path: $path,
            body: '<p>Welcome home</p>',
            excerpt: null,
            metaTitle: 'Home',
            metaDescription: 'Welcome',
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Welcome home',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }

    #[Test]
    public function rootPathFallsBackToEmptyPathContent(): void
    {
        $translation = $this->createTranslation('');

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentAndLocale')->willReturn(null);

        // First call to findByPath with the raw path returns null.
        // Second call with empty string returns the homepage translation.
        $translationRepo->method('findByPath')
            ->willReturnCallback(function (string $locale, string $path) use ($translation): ?ContentTranslation {
                // The empty-path fallback lookup
                if ($path === '') {
                    return $translation;
                }

                return null;
            });

        // Also return empty arrays for hreflang generation
        $translationRepo->method('findByContentId')->willReturn([$translation]);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($this->createPublishedContent());

        $controller = $this->createController($contentRepo, $translationRepo);

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Home Page', (string) $response->getBody());
    }

    #[Test]
    public function rootPathShowsWelcomePageWhenNoContentExists(): void
    {
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByContentAndLocale')->willReturn(null);
        $translationRepo->method('findByPath')->willReturn(null);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);

        $controller = $this->createController($contentRepo, $translationRepo);

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $controller->show($request);

        // Should return 200 with the welcome page (not 404)
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        // The welcome page contains Pulsar CMS branding
        self::assertStringContainsString('Pulsar', $body);
    }

    #[Test]
    public function explicitHomepageContentIdTakesPrecedence(): void
    {
        $translation = $this->createTranslation('');

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);

        // When homepage_content_id is set, findByContentAndLocale is called first
        $translationRepo->method('findByContentAndLocale')
            ->willReturnCallback(function (string $contentId, string $locale) use ($translation): ?ContentTranslation {
                if ($contentId === $this->contentId) {
                    return $translation;
                }
                return null;
            });

        $translationRepo->method('findByContentId')->willReturn([$translation]);

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($this->createPublishedContent());

        $controller = $this->createController(
            $contentRepo,
            $translationRepo,
            homepageContentId: $this->contentId,
        );

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Home Page', (string) $response->getBody());
    }
}
