<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Config\I18nConfig;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Server\Controller\ActivityLogController;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Translator;

final class ActivityLogControllerTest extends TestCase
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
    public function handleReturnsHtmlResponse(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([
            [
                'id' => 1,
                'event_id' => 'evt_1',
                'event_type' => 'http.request',
                'timestamp_us' => 1700000000000000,
                'payload_json' => '{"method":"GET","path":"/api/users"}',
                'request_id' => 'req_abc',
            ],
        ]);
        $store->method('count')->willReturn(1);

        $controller = new ActivityLogController($store);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/studio/console/activity');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getUri')->willReturn($uri);

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('activity-log', (string) $response->getBody());
        self::assertStringContainsString('data-payload', (string) $response->getBody());
    }

    #[Test]
    public function handleWithTypeFilterPassesFilter(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ActivityLogController($store);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['type' => 'db.query']);

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('db.query', (string) $response->getBody());
    }

    #[Test]
    public function handleWithPagination(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(100);

        $controller = new ActivityLogController($store);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['page' => '3']);

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        // JSON payload is HTML-encoded in data-payload attribute
        self::assertStringContainsString('&quot;page&quot;:3', $body);
    }
}
