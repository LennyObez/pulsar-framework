<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\SiteServiceInterface;
use Pulsar\Extension\Analytics\Domain\Site;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Extension\Analytics\Server\Controller\SiteController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class SiteControllerTest extends TestCase
{
    private SiteController $controller;
    private SiteServiceInterface&Stub $siteService;

    protected function setUp(): void
    {
        $this->siteService = $this->createStub(SiteServiceInterface::class);
        $this->controller = new SiteController($this->siteService);
    }

    #[Test]
    public function indexReturnsAllSites(): void
    {
        $now = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $sites = [
            new Site('s-1', 'example.com', 'Example', 'plsr_abc', 'UTC', [], $now, $now),
            new Site('s-2', 'blog.com', 'Blog', 'plsr_def', 'America/New_York', [], $now, $now),
        ];
        $this->siteService->method('listAll')->willReturn($sites);

        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/sites');

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame('s-1', $body['data'][0]['id']);
        self::assertSame('example.com', $body['data'][0]['domain']);
        self::assertSame('plsr_abc', $body['data'][0]['tracking_id']);
        self::assertSame('s-2', $body['data'][1]['id']);
    }

    #[Test]
    public function indexReturnsEmptyArrayWhenNoSites(): void
    {
        $this->siteService->method('listAll')->willReturn([]);

        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/sites');

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
    }

    #[Test]
    public function createReturnsBadRequestWhenDomainMissing(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/api/sites',
            parsedBody: ['name' => 'Test'],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('required', $body['error']);
    }

    #[Test]
    public function createReturnsBadRequestWhenNameMissing(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/api/sites',
            parsedBody: ['domain' => 'test.com'],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createReturnsBadRequestWhenBothEmpty(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/api/sites',
            parsedBody: ['domain' => '', 'name' => ''],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createReturns201OnSuccess(): void
    {
        $now = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $site = new Site('s-new', 'test.com', 'Test Site', 'plsr_xyz', 'Europe/Berlin', [], $now, $now);
        $this->siteService->method('create')->willReturn($site);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/api/sites',
            parsedBody: [
                'domain' => 'test.com',
                'name' => 'Test Site',
                'timezone' => 'Europe/Berlin',
            ],
        );

        $response = $this->controller->create($request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('s-new', $body['id']);
        self::assertSame('test.com', $body['domain']);
        self::assertSame('plsr_xyz', $body['tracking_id']);
    }

    #[Test]
    public function createDefaultsTimezoneToUtc(): void
    {
        $service = $this->createMock(SiteServiceInterface::class);
        $now = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $site = new Site('s-1', 'test.com', 'Test', 'plsr_t', 'UTC', [], $now, $now);
        $service->expects(self::once())
            ->method('create')
            ->with('test.com', 'Test', 'UTC', [])
            ->willReturn($site);

        $controller = new SiteController($service);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/api/sites',
            parsedBody: ['domain' => 'test.com', 'name' => 'Test'],
        );

        $controller->create($request);
    }

    #[Test]
    public function createPassesSettingsWhenProvided(): void
    {
        $service = $this->createMock(SiteServiceInterface::class);
        $now = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $site = new Site('s-1', 'test.com', 'Test', 'plsr_t', 'UTC', ['key' => 'val'], $now, $now);
        $service->expects(self::once())
            ->method('create')
            ->with('test.com', 'Test', 'UTC', ['key' => 'val'])
            ->willReturn($site);

        $controller = new SiteController($service);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/api/sites',
            parsedBody: ['domain' => 'test.com', 'name' => 'Test', 'settings' => ['key' => 'val']],
        );

        $controller->create($request);
    }

    #[Test]
    public function createIgnoresNonArraySettings(): void
    {
        $service = $this->createMock(SiteServiceInterface::class);
        $now = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $site = new Site('s-1', 'test.com', 'Test', 'plsr_t', 'UTC', [], $now, $now);
        $service->expects(self::once())
            ->method('create')
            ->with('test.com', 'Test', 'UTC', [])
            ->willReturn($site);

        $controller = new SiteController($service);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/api/sites',
            parsedBody: ['domain' => 'test.com', 'name' => 'Test', 'settings' => 'not_array'],
        );

        $controller->create($request);
    }

    #[Test]
    public function createReturnsBadRequestWhenParsedBodyNull(): void
    {
        $request = new ServerRequest(method: 'POST', uri: '/analytics/api/sites');

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsSiteWhenFound(): void
    {
        $now = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $site = new Site('s-1', 'example.com', 'Example', 'plsr_abc', 'UTC', ['theme' => 'dark'], $now, $now);
        $this->siteService->method('findById')->willReturn($site);

        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/sites/s-1');

        $response = $this->controller->show($request, 's-1');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('s-1', $body['id']);
        self::assertSame('example.com', $body['domain']);
        self::assertArrayHasKey('settings', $body);
        self::assertSame(['theme' => 'dark'], $body['settings']);
        self::assertArrayHasKey('updated_at', $body);
    }

    #[Test]
    public function showReturns404WhenSiteNotFound(): void
    {
        $this->siteService->method('findById')->willReturn(null);

        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/sites/missing');

        $response = $this->controller->show($request, 'missing');

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('Site not found', $body['error']);
    }

    #[Test]
    public function updateReturnsBadRequestWhenDomainEmpty(): void
    {
        $request = new ServerRequest(
            method: 'PUT',
            uri: '/analytics/api/sites/s-1',
            parsedBody: ['domain' => '', 'name' => 'Test'],
        );

        $response = $this->controller->update($request, 's-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function updateReturnsBadRequestWhenNameEmpty(): void
    {
        $request = new ServerRequest(
            method: 'PUT',
            uri: '/analytics/api/sites/s-1',
            parsedBody: ['domain' => 'test.com', 'name' => ''],
        );

        $response = $this->controller->update($request, 's-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function updateReturnsUpdatedSite(): void
    {
        $now = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $site = new Site('s-1', 'new.com', 'New Name', 'plsr_abc', 'Asia/Tokyo', [], $now, $now);
        $this->siteService->method('update')->willReturn($site);

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/analytics/api/sites/s-1',
            parsedBody: ['domain' => 'new.com', 'name' => 'New Name', 'timezone' => 'Asia/Tokyo'],
        );

        $response = $this->controller->update($request, 's-1');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('new.com', $body['domain']);
        self::assertSame('New Name', $body['name']);
    }

    #[Test]
    public function updateReturns404WhenSiteNotFoundViaException(): void
    {
        $this->siteService->method('update')->willThrowException(
            AnalyticsException::notFound('Site', 's-missing'),
        );

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/analytics/api/sites/s-missing',
            parsedBody: ['domain' => 'test.com', 'name' => 'Test'],
        );

        $response = $this->controller->update($request, 's-missing');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturnsSuccessResponse(): void
    {
        $request = new ServerRequest(method: 'DELETE', uri: '/analytics/api/sites/s-1');

        $response = $this->controller->delete($request, 's-1');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('s-1', $body['id']);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function deleteReturns404WhenSiteNotFound(): void
    {
        $this->siteService->method('delete')->willThrowException(
            AnalyticsException::notFound('Site', 's-missing'),
        );

        $request = new ServerRequest(method: 'DELETE', uri: '/analytics/api/sites/s-missing');

        $response = $this->controller->delete($request, 's-missing');

        self::assertSame(404, $response->getStatusCode());
    }
}
