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

#[CoversClass(ContentController::class)]
final class ContentControllerCacheHeadersTest extends TestCase
{
    private ContentController $controller;
    private CmsKeyManager $keyManager;
    private DateTimeImmutable $updatedAt;

    private string $contentId = '019577a0-0000-7000-8000-000000000001';

    protected function setUp(): void
    {
        $this->keyManager = new CmsKeyManager(MasterKey::fromHex(str_repeat('ab', 32)));

        $this->updatedAt = new DateTimeImmutable('2025-06-15 10:30:00');

        $publishedContent = new Content(
            id: $this->contentId,
            tenantId: null,
            contentType: ContentType::Article,
            authorId: '019577a0-0000-7000-8000-000000000099',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $this->updatedAt,
            createdAt: $this->updatedAt,
            updatedAt: $this->updatedAt,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
            version: 1,
        );

        $translation = new ContentTranslation(
            id: '019577a0-0000-7000-8000-000000000002',
            contentId: $this->contentId,
            locale: 'en',
            title: 'Test Article',
            slugSegment: 'test-article',
            path: 'test-article',
            body: '<p>Test body</p>',
            excerpt: 'Test excerpt',
            metaTitle: 'Test Article',
            metaDescription: 'Test description',
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 1,
            bodyPlaintext: 'Test body',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $contentRepository = $this->createStub(ContentRepositoryInterface::class);
        $contentRepository->method('findById')->willReturn($publishedContent);

        $translationRepository = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepository->method('findByPath')->willReturn($translation);

        $redirectRepository = $this->createStub(RedirectRepositoryInterface::class);
        $redirectRepository->method('findByPath')->willReturn(null);

        $blockRepository = $this->createStub(ContentBlockRepositoryInterface::class);
        $blockRepository->method('findByContentAndLocale')->willReturn([]);

        $fieldRepository = $this->createStub(FieldRegistryRepositoryInterface::class);
        $fieldRepository->method('findValues')->willReturn([]);

        $breadcrumbGenerator = $this->createStub(BreadcrumbGeneratorInterface::class);
        $breadcrumbGenerator->method('generate')->willReturn([]);

        $hreflangTranslationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $hreflangTranslationRepo->method('findByContentId')->willReturn([]);
        $hreflangGenerator = new HreflangGenerator($hreflangTranslationRepo, new UrlPrefixExtractor());

        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en'],
            httpCacheTtlSeconds: 300,
        );

        $safeHtmlPolicy = new SafeHtmlPolicy($this->createStub(AuditLoggerInterface::class));

        $this->controller = new ContentController(
            contentRepository: $contentRepository,
            translationRepository: $translationRepository,
            blockRepository: $blockRepository,
            redirectRepository: $redirectRepository,
            fieldRepository: $fieldRepository,
            breadcrumbGenerator: $breadcrumbGenerator,
            hreflangGenerator: $hreflangGenerator,
            keyManager: $this->keyManager,
            safeHtmlPolicy: $safeHtmlPolicy,
            config: $config,
        );
    }

    #[Test]
    public function published_content_response_has_cache_control_header(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
        );

        $response = $this->controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public, max-age=300, s-maxage=300', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function published_content_response_has_etag_header(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
        );

        $response = $this->controller->show($request);

        $etag = $response->getHeaderLine('ETag');
        self::assertNotSame('', $etag);
        self::assertMatchesRegularExpression('/^"[0-9a-f]+"$/', $etag);
    }

    #[Test]
    public function published_content_response_has_last_modified_header(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
        );

        $response = $this->controller->show($request);

        $lastModified = $response->getHeaderLine('Last-Modified');
        $expected = $this->updatedAt->format('D, d M Y H:i:s') . ' GMT';
        self::assertSame($expected, $lastModified);
    }

    #[Test]
    public function preview_token_response_has_no_store_cache_control(): void
    {
        $token = $this->controller->generatePreviewToken($this->contentId);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
            queryParams: ['preview_token' => $token],
        );

        $response = $this->controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        self::assertSame('', $response->getHeaderLine('ETag'));
        self::assertSame('', $response->getHeaderLine('Last-Modified'));
    }

    #[Test]
    public function if_none_match_returns_304_when_etag_matches(): void
    {
        // First, get the response to learn the ETag
        $firstRequest = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
        );

        $firstResponse = $this->controller->show($firstRequest);
        $etag = $firstResponse->getHeaderLine('ETag');
        self::assertNotSame('', $etag);

        // Second request with If-None-Match
        $conditionalRequest = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: [
                'Accept' => 'application/json',
                'If-None-Match' => $etag,
            ],
        );

        $conditionalResponse = $this->controller->show($conditionalRequest);

        self::assertSame(304, $conditionalResponse->getStatusCode());
        self::assertSame($etag, $conditionalResponse->getHeaderLine('ETag'));
        self::assertStringContainsString('public', $conditionalResponse->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function if_none_match_returns_200_when_etag_does_not_match(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: [
                'Accept' => 'application/json',
                'If-None-Match' => '"stale-etag-value"',
            ],
        );

        $response = $this->controller->show($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function if_modified_since_returns_304_when_content_not_changed(): void
    {
        // Use a date after the content's updatedAt
        $futureDate = $this->updatedAt->modify('+1 hour');
        $ifModifiedSince = $futureDate->format('D, d M Y H:i:s') . ' GMT';

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: [
                'Accept' => 'application/json',
                'If-Modified-Since' => $ifModifiedSince,
            ],
        );

        $response = $this->controller->show($request);

        self::assertSame(304, $response->getStatusCode());
        self::assertStringContainsString('public', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function if_modified_since_returns_200_when_content_is_newer(): void
    {
        // Use a date before the content's updatedAt
        $pastDate = $this->updatedAt->modify('-1 hour');
        $ifModifiedSince = $pastDate->format('D, d M Y H:i:s') . ' GMT';

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: [
                'Accept' => 'application/json',
                'If-Modified-Since' => $ifModifiedSince,
            ],
        );

        $response = $this->controller->show($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function html_response_also_gets_cache_headers(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'text/html'],
        );

        $response = $this->controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public, max-age=300, s-maxage=300', $response->getHeaderLine('Cache-Control'));
        self::assertNotSame('', $response->getHeaderLine('ETag'));
        self::assertNotSame('', $response->getHeaderLine('Last-Modified'));
    }
}
