<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Config\I18nConfig;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Internal\Diagnostics\MemoryTracker;
use Pulsar\Extension\Studio\Server\Controller\HealthDashboardController;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Translator;

final class HealthDashboardControllerTest extends TestCase
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
    public function handleReturnsHtmlWithSystemData(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(42);
        $store->method('sizeInBytes')->willReturn(1024);

        $controller = new HealthDashboardController($store);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('health-dashboard', $body);
        self::assertStringContainsString('data-payload', $body);
    }

    #[Test]
    public function handleIncludesMemoryTrackerData(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(0);
        $store->method('sizeInBytes')->willReturn(0);

        $tracker = new MemoryTracker(minSamplesForDetection: 3);
        $tracker->recordExplicit(1024, 2048, 1);
        $tracker->recordExplicit(2048, 4096, 2);

        $controller = new HealthDashboardController($store, $tracker);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('memory_snapshots', $body);
    }

    #[Test]
    public function handleWithoutMemoryTrackerReturnsEmptySnapshots(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('count')->willReturn(0);
        $store->method('sizeInBytes')->willReturn(0);

        $controller = new HealthDashboardController($store);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        // JSON payload is HTML-encoded in data-payload attribute
        self::assertStringContainsString('&quot;memory_snapshots&quot;:[]', $body);
        self::assertStringContainsString('&quot;leak_report&quot;:null', $body);
    }
}
