<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Pulsar\View\Engine\TemplateEngineInterface;

/**
 * Verifies that ContentController::show() resolves the Pulse template
 * from the content's template field rather than a hardcoded map.
 */
#[CoversClass(ContentController::class)]
final class ContentControllerTemplateResolutionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function templateResolutionProvider(): iterable
    {
        yield 'fully qualified template' => ['cms::public.pages.landing', 'cms::public.pages.landing'];
        yield 'article short name' => ['article', 'cms::public.pages.article'];
        yield 'page short name' => ['page', 'cms::public.pages.page'];
        yield 'custom short name' => ['portfolio', 'cms::public.pages.portfolio'];
        yield 'custom with namespace' => ['theme::layouts.custom', 'theme::layouts.custom'];
    }

    #[Test]
    #[DataProvider('templateResolutionProvider')]
    public function templateFieldDeterminesPulseTemplate(string $templateField, string $expectedPulseTemplate): void
    {
        // Arrange
        $contentId = 'content-001';
        $now = new DateTimeImmutable();

        $content = new Content(
            id: $contentId,
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
            template: $templateField,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
        );

        $translation = new ContentTranslation(
            id: 'tr-001',
            contentId: $contentId,
            locale: 'en',
            title: 'Test Page',
            slugSegment: 'test',
            path: 'test',
            body: '<p>Test</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Test',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findById')->willReturn($content);

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByPath')->willReturn($translation);
        $translationRepo->method('findByContentAndLocale')->willReturn($translation);
        $translationRepo->method('findByContentId')->willReturn([]);

        $blockRepo = $this->createStub(ContentBlockRepositoryInterface::class);
        $blockRepo->method('findByContentAndLocale')->willReturn([]);

        $redirectRepo = $this->createStub(RedirectRepositoryInterface::class);
        $redirectRepo->method('findByPath')->willReturn(null);

        $fieldRepo = $this->createStub(FieldRegistryRepositoryInterface::class);
        $fieldRepo->method('findValues')->willReturn([]);

        $breadcrumbGen = $this->createStub(BreadcrumbGeneratorInterface::class);
        $breadcrumbGen->method('generate')->willReturn([]);

        $hreflangGen = new HreflangGenerator($translationRepo, new UrlPrefixExtractor());

        $keyManager = new CmsKeyManager(MasterKey::fromHex(str_repeat('ab', 32)));

        $safeHtmlPolicy = new SafeHtmlPolicy($this->createStub(AuditLoggerInterface::class));

        $config = CmsConfig::fromArray([
            'site_name' => 'Test Site',
        ]);

        // Track which template name is passed to the engine
        $capturedTemplateName = null;
        $templateEngine = $this->createStub(TemplateEngineInterface::class);
        $templateEngine->method('render')->willReturnCallback(
            function (string $name) use (&$capturedTemplateName): string {
                $capturedTemplateName = $name;
                return '<html><body>Rendered</body></html>';
            },
        );

        $controller = new ContentController(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
            blockRepository: $blockRepo,
            redirectRepository: $redirectRepo,
            fieldRepository: $fieldRepo,
            breadcrumbGenerator: $breadcrumbGen,
            hreflangGenerator: $hreflangGen,
            keyManager: $keyManager,
            safeHtmlPolicy: $safeHtmlPolicy,
            config: $config,
            templateEngine: $templateEngine,
        );

        // Act
        $request = new ServerRequest(
            method: 'GET',
            uri: 'http://localhost/test',
            headers: ['Accept' => 'text/html'],
        );
        $controller->show($request);

        // Assert
        self::assertSame(
            $expectedPulseTemplate,
            $capturedTemplateName,
            "Template field '$templateField' should resolve to '$expectedPulseTemplate'",
        );
    }
}
