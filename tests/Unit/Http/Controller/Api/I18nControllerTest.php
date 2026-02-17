<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Config\I18nConfig;
use Pulsar\Http\Controller\Api\I18nController;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\TranslationEntry;

#[CoversClass(I18nController::class)]
final class I18nControllerTest extends TestCase
{
    #[Test]
    public function returnsJsonForSupportedLocale(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn([
            'save' => new TranslationEntry(key: 'save', message: 'Save'),
            'cancel' => new TranslationEntry(key: 'cancel', message: 'Cancel'),
        ]);

        $controller = new I18nController($catalog, $this->makeConfig(), ['core']);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('en');

        $response = $controller($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Save', $decoded['core.save']);
        self::assertSame('Cancel', $decoded['core.cancel']);
    }

    #[Test]
    public function returnsImmutableCacheHeaders(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn([]);

        $controller = new I18nController($catalog, $this->makeConfig(), ['core']);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('en');

        $response = $controller($request);

        $cacheControl = $response->getHeaderLine('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=31536000', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);
    }

    #[Test]
    public function returns404ForUnsupportedLocale(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $controller = new I18nController($catalog, $this->makeConfig(), ['core']);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('ja');

        $response = $controller($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns400ForInvalidLocaleFormat(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $controller = new I18nController($catalog, $this->makeConfig(), ['core']);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('invalid-locale-format');

        $response = $controller($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function returns400ForEmptyLocale(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $controller = new I18nController($catalog, $this->makeConfig(), ['core']);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('');

        $response = $controller($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function returnsVaryHeader(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn([]);

        $controller = new I18nController($catalog, $this->makeConfig(), ['core']);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('en');

        $response = $controller($request);

        self::assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function mergesMultipleDomains(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturnCallback(
            static function (string $locale, string $domain): array {
                return match ($domain) {
                    'core' => ['save' => new TranslationEntry(key: 'save', message: 'Save')],
                    'messages' => ['welcome' => new TranslationEntry(key: 'welcome', message: 'Welcome')],
                    default => [],
                };
            },
        );

        $controller = new I18nController($catalog, $this->makeConfig(), ['core', 'messages']);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('en');

        $response = $controller($request);

        $body = (string) $response->getBody();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('core.save', $decoded);
        // 'messages' domain uses bare keys
        self::assertArrayHasKey('welcome', $decoded);
    }

    private function makeConfig(): I18nConfig
    {
        return new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr', 'nl', 'de'],
            fallbackLocales: ['en'],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
        );
    }
}
