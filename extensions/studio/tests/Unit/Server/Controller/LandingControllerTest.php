<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Config\I18nConfig;
use Pulsar\Extension\Studio\Config\StudioCollectorConfig;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Config\StudioRetentionConfig;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Server\Controller\LandingController;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Translator;

#[CoversClass(LandingController::class)]
final class LandingControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturn(null);
        $catalog->method('has')->willReturn(false);
        Translator::setGlobalInstance(new Translator($catalog, new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en'],
            fallbackLocales: [],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
        )));
    }

    protected function tearDown(): void
    {
        Translator::resetGlobalInstance();
    }

    #[Test]
    public function handleRendersHtmlViaTemplate(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(42);
        $store->method('sizeInBytes')->willReturn(2048);

        $config = $this->createConfig();
        $controller = new LandingController($config, $store);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        // Verify it uses the layout template (contains full HTML structure)
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('Pulsar Studio', $body);
        self::assertStringContainsString('pulsar-ui.css', $body);
        // Verify landing-page content is present (i18n keys render as-is in tests)
        self::assertStringContainsString('studio.events_recorded', $body);
        self::assertStringContainsString('42', $body);
    }

    #[Test]
    public function handleWithNoStoreRendersDefaults(): void
    {
        $config = $this->createConfig();
        $controller = new LandingController($config);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('0 B', $body);
    }

    private function createConfig(): StudioConfig
    {
        return new StudioConfig(
            storagePath: '/tmp/studio',
            samplingRate: 1.0,
            retention: new StudioRetentionConfig(maxAgeDays: 7, maxSizeMb: 100),
            collectors: new StudioCollectorConfig(
                http: true,
                database: true,
                logs: true,
                exceptions: true,
                scheduler: false,
                featureFlags: false,
            ),
        );
    }
}
