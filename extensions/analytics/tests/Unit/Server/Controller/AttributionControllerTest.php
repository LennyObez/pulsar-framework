<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\AttributionServiceInterface;
use Pulsar\Extension\Analytics\Domain\AttributionModel;
use Pulsar\Extension\Analytics\Domain\AttributionResult;
use Pulsar\Extension\Analytics\Server\Controller\AttributionController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class AttributionControllerTest extends TestCase
{
    private AttributionController $controller;
    private AttributionServiceInterface&Stub $attributionService;

    protected function setUp(): void
    {
        $this->attributionService = $this->createStub(AttributionServiceInterface::class);
        $this->controller = new AttributionController($this->attributionService);
    }

    #[Test]
    public function calculateReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/attribution');

        $response = $this->controller->calculate($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function calculateReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/attribution',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->calculate($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function calculateReturnsBadRequestForInvalidModel(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/attribution',
            queryParams: ['site_id' => 'site-001', 'model' => 'invalid_model'],
        );

        $response = $this->controller->calculate($request);

        self::assertSame(400, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Invalid model', $body['error']);
    }

    #[Test]
    public function calculateReturnsAttributionResults(): void
    {
        $results = [
            new AttributionResult('Google', 50, 1200.50, 1.0),
            new AttributionResult('Direct', 30, 800.00, 1.0),
        ];
        $this->attributionService->method('calculate')->willReturn($results);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/attribution',
            queryParams: ['site_id' => 'site-001', 'model' => 'last_touch'],
        );

        $response = $this->controller->calculate($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('last_touch', $body['model']);
        self::assertCount(2, $body['data']);
        self::assertSame('Google', $body['data'][0]['source']);
        self::assertSame(50, $body['data'][0]['conversions']);
        self::assertSame(1200.50, $body['data'][0]['revenue']);
    }

    #[Test]
    public function calculateDefaultsToLastTouch(): void
    {
        $service = $this->createMock(AttributionServiceInterface::class);
        $service->expects(self::once())
            ->method('calculate')
            ->with('site-001', self::anything(), self::anything(), AttributionModel::LastTouch, null)
            ->willReturn([]);

        $controller = new AttributionController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/attribution',
            queryParams: ['site_id' => 'site-001'],
        );

        $controller->calculate($request);
    }

    #[Test]
    public function calculatePassesGoalIdWhenProvided(): void
    {
        $service = $this->createMock(AttributionServiceInterface::class);
        $service->expects(self::once())
            ->method('calculate')
            ->with('site-001', self::anything(), self::anything(), AttributionModel::FirstTouch, 'goal-123')
            ->willReturn([]);

        $controller = new AttributionController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/attribution',
            queryParams: [
                'site_id' => 'site-001',
                'model' => 'first_touch',
                'goal_id' => 'goal-123',
            ],
        );

        $controller->calculate($request);
    }

    #[Test]
    public function calculatePassesNullGoalIdWhenEmpty(): void
    {
        $service = $this->createMock(AttributionServiceInterface::class);
        $service->expects(self::once())
            ->method('calculate')
            ->with('site-001', self::anything(), self::anything(), AttributionModel::LastTouch, null)
            ->willReturn([]);

        $controller = new AttributionController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/attribution',
            queryParams: ['site_id' => 'site-001', 'goal_id' => ''],
        );

        $controller->calculate($request);
    }

    #[Test]
    public function compareReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/attribution/compare');

        $response = $this->controller->compare($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function compareReturnsComparisonData(): void
    {
        $comparison = [
            'first_touch' => [new AttributionResult('Google', 60, 1500.00, 1.0)],
            'last_touch' => [new AttributionResult('Google', 40, 1000.00, 1.0)],
        ];
        $this->attributionService->method('compareModels')->willReturn($comparison);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/attribution/compare',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->compare($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('first_touch', $body['data']);
        self::assertArrayHasKey('last_touch', $body['data']);
        self::assertSame(60, $body['data']['first_touch'][0]['conversions']);
    }

    #[Test]
    public function compareReturnsEmptyWhenNoData(): void
    {
        $this->attributionService->method('compareModels')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/attribution/compare',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->compare($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
    }
}
