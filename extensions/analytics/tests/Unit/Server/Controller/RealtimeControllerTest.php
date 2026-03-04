<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Extension\Analytics\Server\Controller\RealtimeController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class RealtimeControllerTest extends TestCase
{
    private RealtimeController $controller;
    private StatsServiceInterface&Stub $statsService;

    protected function setUp(): void
    {
        $this->statsService = $this->createStub(StatsServiceInterface::class);
        $this->controller = new RealtimeController($this->statsService);
    }

    #[Test]
    public function realtimeReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/realtime');

        $response = $this->controller->realtime($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('site_id is required', $body['error']);
    }

    #[Test]
    public function realtimeReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/realtime',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->realtime($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function realtimeReturnsBadRequestWhenSiteIdIsNonString(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/realtime',
            queryParams: ['site_id' => ['array']],
        );

        $response = $this->controller->realtime($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function realtimeReturnsVisitorCountAndActivePages(): void
    {
        $realtimeData = [
            'current_visitors' => 42,
            'active_pages' => [
                ['pathname' => '/', 'visitors' => 15],
                ['pathname' => '/about', 'visitors' => 8],
                ['pathname' => '/blog', 'visitors' => 5],
            ],
        ];
        $this->statsService->method('getRealtime')->willReturn($realtimeData);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/realtime',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->realtime($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(42, $body['current_visitors']);
        self::assertCount(3, $body['active_pages']);
        self::assertSame('/', $body['active_pages'][0]['pathname']);
        self::assertSame(15, $body['active_pages'][0]['visitors']);
    }

    #[Test]
    public function realtimeReturnsZeroVisitorsWhenIdle(): void
    {
        $realtimeData = [
            'current_visitors' => 0,
            'active_pages' => [],
        ];
        $this->statsService->method('getRealtime')->willReturn($realtimeData);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/realtime',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->realtime($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['current_visitors']);
        self::assertSame([], $body['active_pages']);
    }

    #[Test]
    public function realtimePassesSiteIdToService(): void
    {
        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getRealtime')
            ->with('site-specific-id')
            ->willReturn(['current_visitors' => 0, 'active_pages' => []]);

        $controller = new RealtimeController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/realtime',
            queryParams: ['site_id' => 'site-specific-id'],
        );

        $controller->realtime($request);
    }
}
