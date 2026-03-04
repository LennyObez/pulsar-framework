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

use function str_repeat;

#[CoversClass(ContentController::class)]
final class ContentControllerNotFoundTest extends TestCase
{
    private ContentController $controller;

    protected function setUp(): void
    {
        $keyManager = new CmsKeyManager(MasterKey::fromHex(str_repeat('ab', 32)));

        $translationRepository = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepository->method('findByPath')->willReturn(null);
        $translationRepository->method('findByContentAndLocale')->willReturn(null);
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
    public function nonExistentPathReturnsJsonForApiClients(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/nonexistent-page',
            headers: ['Accept' => 'application/json'],
        );

        $response = $this->controller->show($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Content not found', (string) $response->getBody());
    }

    #[Test]
    public function nonExistentPathReturnsHtmlForBrowsers(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/nonexistent-page',
            headers: ['Accept' => 'text/html,application/xhtml+xml'],
        );

        $response = $this->controller->show($request);

        self::assertSame(404, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('Page Not Found', $body);
        self::assertStringContainsString('Back to Homepage', $body);
    }

    #[Test]
    public function nonExistentPathReturnsHtmlByDefault(): void
    {
        // No Accept header - should default to HTML
        $request = new ServerRequest(
            method: 'GET',
            uri: '/nonexistent-page',
        );

        $response = $this->controller->show($request);

        self::assertSame(404, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('<!DOCTYPE html>', $body);
    }
}
