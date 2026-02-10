<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
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

use function dirname;
use function file_get_contents;
use function is_readable;
use function str_contains;
use function str_repeat;

#[CoversClass(ContentController::class)]
final class ContentControllerWelcomeTest extends TestCase
{
    private ContentController $controller;

    protected function setUp(): void
    {
        $keyManager = new CmsKeyManager(MasterKey::fromHex(str_repeat('ab', 32)));

        $translationRepository = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepository->method('findByPath')->willReturn(null);
        $translationRepository->method('findByContentId')->willReturn([]);

        $redirectRepository = $this->createStub(RedirectRepositoryInterface::class);
        $redirectRepository->method('findByPath')->willReturn(null);

        $hreflangGenerator = new HreflangGenerator(
            $this->createStub(ContentTranslationRepositoryInterface::class),
            new UrlPrefixExtractor(),
        );

        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en'],
        );

        $safeHtmlPolicy = new SafeHtmlPolicy($this->createStub(AuditLoggerInterface::class));

        $this->controller = new ContentController(
            contentRepository: $this->createStub(ContentRepositoryInterface::class),
            translationRepository: $translationRepository,
            blockRepository: $this->createStub(ContentBlockRepositoryInterface::class),
            redirectRepository: $redirectRepository,
            fieldRepository: $this->createStub(FieldRegistryRepositoryInterface::class),
            breadcrumbGenerator: $this->createStub(BreadcrumbGeneratorInterface::class),
            hreflangGenerator: $hreflangGenerator,
            keyManager: $keyManager,
            safeHtmlPolicy: $safeHtmlPolicy,
            config: $config,
        );
    }

    #[Test]
    public function welcome_page_template_file_exists_and_is_readable(): void
    {
        $templatePath = dirname(__DIR__, 6) . '/extensions/cms/resources/views/welcome.pulsar.php';

        self::assertFileExists($templatePath);
        self::assertTrue(is_readable($templatePath));
    }

    #[Test]
    public function welcome_page_template_contains_expected_content(): void
    {
        $templatePath = dirname(__DIR__, 6) . '/extensions/cms/resources/views/welcome.pulsar.php';
        $html = file_get_contents($templatePath);

        self::assertIsString($html);
        self::assertTrue(str_contains($html, 'Pulsar CMS'));
        self::assertTrue(str_contains($html, 'Quick Start'));
        self::assertTrue(str_contains($html, '/admin/cms'));
        self::assertTrue(str_contains($html, '/admin/cms/content'));
    }

    #[Test]
    public function root_path_returns_welcome_page_with_200_status(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
        );

        $response = $this->controller->show($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function root_path_response_body_contains_pulsar_cms_heading(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
        );

        $response = $this->controller->show($request);
        $body = (string) $response->getBody();

        self::assertTrue(str_contains($body, 'Pulsar CMS'));
    }

    #[Test]
    public function root_path_response_body_contains_quick_start_section(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
        );

        $response = $this->controller->show($request);
        $body = (string) $response->getBody();

        self::assertTrue(str_contains($body, 'Quick Start'));
    }

    #[Test]
    public function root_path_response_body_contains_admin_link(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
        );

        $response = $this->controller->show($request);
        $body = (string) $response->getBody();

        self::assertTrue(str_contains($body, '/admin/cms'));
    }
}
