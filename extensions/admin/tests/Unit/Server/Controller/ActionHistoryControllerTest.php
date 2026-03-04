<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use Pulsar\Extension\Admin\Server\Controller\ActionHistoryController;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Translator;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class ActionHistoryControllerTest extends TestCase
{
    private ActionHistoryController $controller;
    private ActionHistoryStoreInterface&Stub $store;

    protected function setUp(): void
    {
        // Boot the I18n translator so admin layout templates can call __()
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturn(null);
        $catalog->method('has')->willReturn(false);
        $i18nConfig = new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en'],
            fallbackLocales: [],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
        );
        Translator::setGlobalInstance(new Translator($catalog, $i18nConfig));

        $this->store = $this->createStub(ActionHistoryStoreInterface::class);
        $config = AdminConfig::fromArray(['schema' => ['enabled' => true]]);
        $this->controller = new ActionHistoryController($this->store, $config);
    }

    protected function tearDown(): void
    {
        Translator::resetGlobalInstance();
    }

    #[Test]
    public function recentReturnsJsonForJsonAcceptHeader(): void
    {
        $entries = [
            new ActionHistoryEntry('ah-1', 'create', 'posts', 'post-1', 'admin', 1700000000, true, 'Created post'),
        ];
        $this->store->method('recent')->willReturn($entries);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/activity',
            headers: ['Accept' => 'application/json'],
        );

        $response = $this->controller->recent($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['entries']);
        self::assertSame('ah-1', $body['entries'][0]['id']);
        self::assertSame('create', $body['entries'][0]['action']);
        self::assertSame('posts', $body['entries'][0]['resource']);
        self::assertSame('post-1', $body['entries'][0]['record_id']);
        self::assertTrue($body['entries'][0]['success']);
    }

    #[Test]
    public function recentReturnsHtmlForHtmlAcceptHeader(): void
    {
        $this->store->method('recent')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/activity',
            headers: ['Accept' => 'text/html'],
        );

        $response = $this->controller->recent($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function recentDefaultsTo50Limit(): void
    {
        $store = $this->createMock(ActionHistoryStoreInterface::class);
        $store->expects(self::once())
            ->method('recent')
            ->with(50)
            ->willReturn([]);

        $config = AdminConfig::fromArray(['schema' => ['enabled' => false]]);
        $controller = new ActionHistoryController($store, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/activity',
            headers: ['Accept' => 'application/json'],
        );

        $controller->recent($request);
    }

    #[Test]
    public function recentClampsLimitToMinimum1(): void
    {
        $store = $this->createMock(ActionHistoryStoreInterface::class);
        $store->expects(self::once())
            ->method('recent')
            ->with(1)
            ->willReturn([]);

        $config = AdminConfig::fromArray(['schema' => ['enabled' => false]]);
        $controller = new ActionHistoryController($store, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/activity',
            headers: ['Accept' => 'application/json'],
            queryParams: ['limit' => '0'],
        );

        $controller->recent($request);
    }

    #[Test]
    public function recentClampsLimitToMaximum100(): void
    {
        $store = $this->createMock(ActionHistoryStoreInterface::class);
        $store->expects(self::once())
            ->method('recent')
            ->with(100)
            ->willReturn([]);

        $config = AdminConfig::fromArray(['schema' => ['enabled' => false]]);
        $controller = new ActionHistoryController($store, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/activity',
            headers: ['Accept' => 'application/json'],
            queryParams: ['limit' => '999'],
        );

        $controller->recent($request);
    }

    #[Test]
    public function forResourceReturnsJsonForJsonAcceptHeader(): void
    {
        $entries = [
            new ActionHistoryEntry('ah-1', 'update', 'users', 'user-1', 'admin', 1700000000, true, 'Updated user'),
            new ActionHistoryEntry('ah-2', 'delete', 'users', 'user-2', 'admin', 1700000100, false, 'Delete failed'),
        ];
        $this->store->method('forResource')->willReturn($entries);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/activity/users',
            headers: ['Accept' => 'application/json'],
        );

        $response = $this->controller->forResource($request, 'users');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['entries']);
        self::assertSame('update', $body['entries'][0]['action']);
        self::assertFalse($body['entries'][1]['success']);
    }

    #[Test]
    public function forResourceReturnsHtmlWhenNoJsonAccept(): void
    {
        $this->store->method('forResource')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/activity/users',
        );

        $response = $this->controller->forResource($request, 'users');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function forResourcePassesCustomLimit(): void
    {
        $store = $this->createMock(ActionHistoryStoreInterface::class);
        $store->expects(self::once())
            ->method('forResource')
            ->with('posts', 25)
            ->willReturn([]);

        $config = AdminConfig::fromArray(['schema' => ['enabled' => false]]);
        $controller = new ActionHistoryController($store, $config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/activity/posts',
            headers: ['Accept' => 'application/json'],
            queryParams: ['limit' => '25'],
        );

        $controller->forResource($request, 'posts');
    }

    #[Test]
    public function recentSerializesNullRecordId(): void
    {
        $entries = [
            new ActionHistoryEntry('ah-1', 'bulk_delete', 'posts', null, 'admin', 1700000000, true, 'Bulk delete'),
        ];
        $this->store->method('recent')->willReturn($entries);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/activity',
            headers: ['Accept' => 'application/json'],
        );

        $response = $this->controller->recent($request);
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertNull($body['entries'][0]['record_id']);
    }
}
