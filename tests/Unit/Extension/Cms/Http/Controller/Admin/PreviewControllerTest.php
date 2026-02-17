<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\Http\Controller\Admin\PreviewController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

#[CoversClass(PreviewController::class)]
final class PreviewControllerTest extends TestCase
{
    private PreviewController $controller;
    private ContentRepositoryInterface $contentRepository;
    private ContentTranslationRepositoryInterface $translationRepository;
    private ContentBlockRepositoryInterface $blockRepository;

    private string $contentId = '019577a0-0000-7000-8000-000000000001';

    protected function setUp(): void
    {
        $now = new DateTimeImmutable();

        $content = new Content(
            id: $this->contentId,
            tenantId: null,
            contentType: ContentType::Article,
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
            version: 1,
        );

        $translation = new ContentTranslation(
            id: '019577a0-0000-7000-8000-000000000002',
            contentId: $this->contentId,
            locale: 'en',
            title: 'Test article',
            slugSegment: 'test-article',
            path: 'test-article',
            body: '<p>Test body content</p>',
            excerpt: 'Test excerpt',
            metaTitle: 'Test article',
            metaDescription: 'A test description',
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 2,
            bodyPlaintext: 'Test body content',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $this->contentRepository = $this->createStub(ContentRepositoryInterface::class);
        $this->contentRepository->method('findById')->willReturn($content);

        $this->translationRepository = $this->createStub(ContentTranslationRepositoryInterface::class);
        $this->translationRepository->method('findByContentAndLocale')->willReturn($translation);

        $this->blockRepository = $this->createStub(ContentBlockRepositoryInterface::class);
        $this->blockRepository->method('findByContentAndLocale')->willReturn([]);

        $safeHtmlPolicy = new SafeHtmlPolicy($this->createStub(AuditLoggerInterface::class));

        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(true);
        $gate->method('denies')->willReturn(false);

        $this->controller = new PreviewController(
            contentRepository: $this->contentRepository,
            translationRepository: $this->translationRepository,
            blockRepository: $this->blockRepository,
            safeHtmlPolicy: $safeHtmlPolicy,
            blockRenderer: null,
            templateEngine: null,
            gate: $gate,
        );
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $postData
     */
    private function makeAuthenticatedRequest(string $method, string $uri, array $queryParams = [], array $postData = []): ServerRequest
    {
        $identity = new Identity(
            id: 'admin-1',
            displayName: 'Admin',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Verified,
        );

        $request = new ServerRequest(
            method: $method,
            uri: $uri,
            headers: ['Accept' => 'application/json'],
            queryParams: $queryParams,
        );

        if ($method === 'POST' && $postData !== []) {
            $request = $request->withParsedBody($postData);
        }

        return $request->withAttribute('identity', $identity);
    }

    #[Test]
    public function show_returns_json_with_content_data_when_no_template_engine(): void
    {
        $request = $this->makeAuthenticatedRequest('GET', "/admin/cms/content/{$this->contentId}/preview", ['locale' => 'en']);

        $response = $this->controller->show($request, $this->contentId);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        /** @var array<string, mixed> $content */
        $content = $data['content'];
        /** @var array<string, mixed> $translation */
        $translation = $data['translation'];
        self::assertSame($this->contentId, $content['id']);
        self::assertSame('article', $content['type']);
        self::assertSame('Test article', $translation['title']);
    }

    #[Test]
    public function show_returns_404_for_nonexistent_content(): void
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn(null);

        $controller = new PreviewController(
            contentRepository: $contentRepo,
            translationRepository: $this->translationRepository,
            blockRepository: $this->blockRepository,
            safeHtmlPolicy: new SafeHtmlPolicy($this->createStub(AuditLoggerInterface::class)),
        );

        $request = $this->makeAuthenticatedRequest('GET', '/admin/cms/content/nonexistent/preview');

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function show_requires_authentication(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: "/admin/cms/content/{$this->contentId}/preview",
            headers: ['Accept' => 'application/json'],
        );

        $this->expectException(AuthenticationException::class);
        $this->controller->show($request, $this->contentId);
    }

    #[Test]
    public function render_get_returns_html_with_persisted_content(): void
    {
        $request = $this->makeAuthenticatedRequest('GET', "/admin/cms/content/{$this->contentId}/preview/render", ['locale' => 'en']);

        $response = $this->controller->render($request, $this->contentId);

        self::assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringContainsString('Test article', $body);
        self::assertStringContainsString('Test body content', $body);
        self::assertStringContainsString('data-theme="light"', $body);
        self::assertStringContainsString('data-extension="cms"', $body);
    }

    #[Test]
    public function render_get_sets_no_cache_headers(): void
    {
        $request = $this->makeAuthenticatedRequest('GET', "/admin/cms/content/{$this->contentId}/preview/render");

        $response = $this->controller->render($request, $this->contentId);

        self::assertSame('no-store, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
    }

    #[Test]
    public function render_post_uses_submitted_body_for_live_preview(): void
    {
        $request = $this->makeAuthenticatedRequest('POST', "/admin/cms/content/{$this->contentId}/preview/render", ['locale' => 'en'], [
            'title' => 'Updated title',
            'body' => '<p>Updated body content</p>',
            'excerpt' => 'Updated excerpt',
            'blocks_json' => '',
        ]);

        $response = $this->controller->render($request, $this->contentId);

        $body = (string) $response->getBody();
        self::assertStringContainsString('Updated title', $body);
        self::assertStringContainsString('Updated body content', $body);
        self::assertStringNotContainsString('Test article', $body);
    }

    #[Test]
    public function render_post_sanitizes_html_body(): void
    {
        $request = $this->makeAuthenticatedRequest('POST', "/admin/cms/content/{$this->contentId}/preview/render", ['locale' => 'en'], [
            'title' => 'Safe title',
            'body' => '<p>Safe content</p><script>alert("xss")</script>',
            'excerpt' => '',
            'blocks_json' => '',
        ]);

        $response = $this->controller->render($request, $this->contentId);

        $body = (string) $response->getBody();
        self::assertStringContainsString('Safe content', $body);
        self::assertStringNotContainsString('<script>', $body);
    }

    #[Test]
    public function render_post_escapes_title_in_html(): void
    {
        $request = $this->makeAuthenticatedRequest('POST', "/admin/cms/content/{$this->contentId}/preview/render", ['locale' => 'en'], [
            'title' => '<img src=x onerror=alert(1)>',
            'body' => '<p>body</p>',
            'excerpt' => '',
            'blocks_json' => '',
        ]);

        $response = $this->controller->render($request, $this->contentId);

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('<img src=x', $body);
        self::assertStringContainsString('&lt;img', $body);
    }

    #[Test]
    public function render_returns_valid_html_document(): void
    {
        $request = $this->makeAuthenticatedRequest('GET', "/admin/cms/content/{$this->contentId}/preview/render", ['locale' => 'fr']);

        $response = $this->controller->render($request, $this->contentId);

        $body = (string) $response->getBody();
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('lang="fr"', $body);
        self::assertStringContainsString('</html>', $body);
    }

    #[Test]
    public function render_post_with_empty_fields_produces_empty_preview(): void
    {
        $request = $this->makeAuthenticatedRequest('POST', "/admin/cms/content/{$this->contentId}/preview/render", [], [
            'title' => '',
            'body' => '',
            'excerpt' => '',
            'blocks_json' => '',
        ]);

        $response = $this->controller->render($request, $this->contentId);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('<!DOCTYPE html>', $body);
    }

    #[Test]
    public function show_uses_locale_from_query_params(): void
    {
        $request = $this->makeAuthenticatedRequest('GET', "/admin/cms/content/{$this->contentId}/preview", ['locale' => 'de']);

        $response = $this->controller->show($request, $this->contentId);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        self::assertSame('de', $data['locale']);
        $previewUrl = $data['previewUrl'];
        self::assertIsString($previewUrl);
        self::assertStringContainsString('locale=de', $previewUrl);
    }

    #[Test]
    public function show_defaults_locale_to_en(): void
    {
        $request = $this->makeAuthenticatedRequest('GET', "/admin/cms/content/{$this->contentId}/preview");

        $response = $this->controller->show($request, $this->contentId);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        self::assertSame('en', $data['locale']);
    }

    #[Test]
    public function show_includes_preview_url_in_response(): void
    {
        $request = $this->makeAuthenticatedRequest('GET', "/admin/cms/content/{$this->contentId}/preview", ['locale' => 'en']);

        $response = $this->controller->show($request, $this->contentId);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        $previewUrl = $data['previewUrl'];
        self::assertIsString($previewUrl);
        self::assertStringContainsString("/admin/cms/content/{$this->contentId}/preview/render", $previewUrl);
    }

    #[Test]
    public function show_returns_404_for_deleted_content(): void
    {
        $now = new DateTimeImmutable();
        $deletedContent = new Content(
            id: $this->contentId,
            tenantId: null,
            contentType: ContentType::Article,
            authorId: 'author-1',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $now,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: $now,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
            version: 1,
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($deletedContent);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(true);
        $gate->method('denies')->willReturn(false);

        $controller = new PreviewController(
            contentRepository: $contentRepo,
            translationRepository: $this->translationRepository,
            blockRepository: $this->blockRepository,
            safeHtmlPolicy: new SafeHtmlPolicy($this->createStub(AuditLoggerInterface::class)),
            gate: $gate,
        );

        $request = $this->makeAuthenticatedRequest('GET', "/admin/cms/content/{$this->contentId}/preview");

        $response = $controller->show($request, $this->contentId);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function show_includes_translation_data_when_translation_is_null(): void
    {
        $transRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $transRepo->method('findByContentAndLocale')->willReturn(null);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(true);
        $gate->method('denies')->willReturn(false);

        $controller = new PreviewController(
            contentRepository: $this->contentRepository,
            translationRepository: $transRepo,
            blockRepository: $this->blockRepository,
            safeHtmlPolicy: new SafeHtmlPolicy($this->createStub(AuditLoggerInterface::class)),
            gate: $gate,
        );

        $request = $this->makeAuthenticatedRequest('GET', "/admin/cms/content/{$this->contentId}/preview");

        $response = $controller->show($request, $this->contentId);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        /** @var array<string, mixed> $translationData */
        $translationData = $data['translation'];
        self::assertSame('', $translationData['title']);
        self::assertSame('', $translationData['body']);
    }

    #[Test]
    public function render_content_type_is_html(): void
    {
        $request = $this->makeAuthenticatedRequest('GET', "/admin/cms/content/{$this->contentId}/preview/render");

        $response = $this->controller->render($request, $this->contentId);

        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }
}
