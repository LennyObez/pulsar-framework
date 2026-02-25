<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller;

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

use function hash_hmac;
use function str_repeat;
use function strlen;
use function time;

#[CoversClass(ContentController::class)]
final class ContentControllerPreviewTokenTest extends TestCase
{
    private ContentController $controller;
    private CmsKeyManager $keyManager;
    private ContentRepositoryInterface $contentRepository;
    private ContentTranslationRepositoryInterface $translationRepository;

    private string $contentId = '019577a0-0000-7000-8000-000000000001';

    protected function setUp(): void
    {
        $this->keyManager = new CmsKeyManager(MasterKey::fromHex(str_repeat('ab', 32)));

        $now = new DateTimeImmutable();

        $draftContent = new Content(
            id: $this->contentId,
            tenantId: null,
            contentType: ContentType::Article,
            authorId: '019577a0-0000-7000-8000-000000000099',
            status: PublishingStatus::Draft,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: null,
            createdAt: $now,
            updatedAt: $now,
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

        $this->contentRepository = $this->createStub(ContentRepositoryInterface::class);
        $this->contentRepository->method('findById')->willReturn($draftContent);

        $this->translationRepository = $this->createStub(ContentTranslationRepositoryInterface::class);
        $this->translationRepository->method('findByPath')->willReturn($translation);

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
        );

        $safeHtmlPolicy = new SafeHtmlPolicy($this->createStub(AuditLoggerInterface::class));

        $this->controller = new ContentController(
            contentRepository: $this->contentRepository,
            translationRepository: $this->translationRepository,
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
    public function valid_preview_token_allows_access_to_draft_content(): void
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
    }

    #[Test]
    public function forged_token_with_garbage_signature_is_rejected(): void
    {
        $forgedToken = (time() + 3600) . '.forged-garbage-signature';

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
            queryParams: ['preview_token' => $forgedToken],
        );

        $response = $this->controller->show($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function forged_token_with_wrong_content_id_signature_is_rejected(): void
    {
        // Generate a valid token for a different content ID
        $wrongContentToken = $this->controller->generatePreviewToken('different-content-id');

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
            queryParams: ['preview_token' => $wrongContentToken],
        );

        $response = $this->controller->show($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function expired_preview_token_is_rejected(): void
    {
        // Create a token that expired 1 second ago
        $expiryTimestamp = time() - 1;
        $signature = hash_hmac(
            'sha256',
            $this->contentId . '|' . $expiryTimestamp,
            $this->keyManager->previewKey(),
        );
        $expiredToken = $expiryTimestamp . '.' . $signature;

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
            queryParams: ['preview_token' => $expiredToken],
        );

        $response = $this->controller->show($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function empty_preview_token_is_rejected(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
            queryParams: ['preview_token' => ''],
        );

        $response = $this->controller->show($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function malformed_preview_token_without_dot_is_rejected(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
            queryParams: ['preview_token' => 'no-dot-separator'],
        );

        $response = $this->controller->show($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function no_preview_token_returns_404_for_draft_content(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
        );

        $response = $this->controller->show($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function generate_preview_token_produces_valid_format(): void
    {
        $token = $this->controller->generatePreviewToken($this->contentId, 7200);

        $parts = explode('.', $token, 2);
        self::assertCount(2, $parts);

        $expiryTimestamp = (int) $parts[0];
        self::assertGreaterThan(time(), $expiryTimestamp);
        self::assertLessThanOrEqual(time() + 7200 + 1, $expiryTimestamp);

        // Signature should be a 64-char hex string (sha256)
        self::assertSame(64, strlen($parts[1]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $parts[1]);
    }

    #[Test]
    public function token_with_tampered_expiry_is_rejected(): void
    {
        $token = $this->controller->generatePreviewToken($this->contentId);
        $parts = explode('.', $token, 2);

        // Tamper with the expiry timestamp (extend it)
        $tamperedToken = ((int) $parts[0] + 86400) . '.' . $parts[1];

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test-article',
            headers: ['Accept' => 'application/json'],
            queryParams: ['preview_token' => $tamperedToken],
        );

        $response = $this->controller->show($request);

        self::assertSame(404, $response->getStatusCode());
    }
}
