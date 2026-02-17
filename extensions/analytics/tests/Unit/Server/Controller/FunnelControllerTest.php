<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\FunnelServiceInterface;
use Pulsar\Extension\Analytics\Domain\FunnelDefinition;
use Pulsar\Extension\Analytics\Domain\FunnelResult;
use Pulsar\Extension\Analytics\Domain\FunnelStep;
use Pulsar\Extension\Analytics\Domain\FunnelStepResult;
use Pulsar\Extension\Analytics\Domain\FunnelStepType;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Extension\Analytics\Server\Controller\FunnelController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class FunnelControllerTest extends TestCase
{
    private FunnelController $controller;
    private FunnelServiceInterface&Stub $funnelService;

    protected function setUp(): void
    {
        $this->funnelService = $this->createStub(FunnelServiceInterface::class);
        $this->controller = new FunnelController($this->funnelService);
    }

    #[Test]
    public function indexReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/funnels');

        $response = $this->controller->index($request);

        self::assertSame(400, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('site_id is required', $body['error']);
    }

    #[Test]
    public function indexReturnsBadRequestWhenSiteIdIsEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/funnels',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->index($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function indexReturnsBadRequestWhenSiteIdIsNonString(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/funnels',
            queryParams: ['site_id' => ['array']],
        );

        $response = $this->controller->index($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function indexReturnsEmptyDataForSiteWithNoFunnels(): void
    {
        $this->funnelService->method('listForSite')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/funnels',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
    }

    #[Test]
    public function indexReturnsFunnelsWithSteps(): void
    {
        $funnels = [
            new FunnelDefinition(
                id: 'f-001',
                siteId: 'site-001',
                name: 'Checkout Funnel',
                steps: [
                    new FunnelStep(1, 'Landing', FunnelStepType::PageVisit, '/'),
                    new FunnelStep(2, 'Cart', FunnelStepType::PageVisit, '/cart'),
                ],
                createdAt: new DateTimeImmutable('2025-06-01'),
            ),
        ];
        $this->funnelService->method('listForSite')->willReturn($funnels);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/funnels',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['data']);
        self::assertSame('f-001', $body['data'][0]['id']);
        self::assertSame('Checkout Funnel', $body['data'][0]['name']);
        self::assertCount(2, $body['data'][0]['steps']);
        self::assertSame('page_visit', $body['data'][0]['steps'][0]['type']);
    }

    #[Test]
    public function createReturnsBadRequestWhenFieldsMissing(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/plsr/api/v1/funnels',
            parsedBody: ['site_id' => 'site-001'],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createReturnsBadRequestWhenStepsEmpty(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/plsr/api/v1/funnels',
            parsedBody: ['site_id' => 'site-001', 'name' => 'Test', 'steps' => []],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createReturnsBadRequestForInvalidStep(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/plsr/api/v1/funnels',
            parsedBody: [
                'site_id' => 'site-001',
                'name' => 'Test',
                'steps' => [
                    ['name' => 'Step 1', 'type' => 'invalid_type', 'value' => '/page'],
                ],
            ],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createReturns201OnSuccess(): void
    {
        $this->funnelService->method('create')->willReturn(
            new FunnelDefinition(
                id: 'f-new',
                siteId: 'site-001',
                name: 'Signup Flow',
                steps: [new FunnelStep(1, 'Register', FunnelStepType::PageVisit, '/register')],
                createdAt: new DateTimeImmutable('2025-06-01'),
            ),
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/plsr/api/v1/funnels',
            parsedBody: [
                'site_id' => 'site-001',
                'name' => 'Signup Flow',
                'steps' => [
                    ['name' => 'Register', 'type' => 'page_visit', 'value' => '/register'],
                ],
            ],
        );

        $response = $this->controller->create($request);

        self::assertSame(201, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('f-new', $body['id']);
        self::assertSame('Signup Flow', $body['name']);
    }

    #[Test]
    public function evaluateReturnsFunnelResult(): void
    {
        $result = new FunnelResult(
            funnelId: 'f-001',
            steps: [
                new FunnelStepResult(1, 'Landing', 100, 0.0, 100.0),
                new FunnelStepResult(2, 'Cart', 60, 40.0, 60.0),
            ],
            overallConversionRate: 60.0,
        );
        $this->funnelService->method('evaluate')->willReturn($result);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/funnels/f-001/evaluate',
            queryParams: ['from' => '2025-01-01', 'to' => '2025-01-31'],
        );

        $response = $this->controller->evaluate($request, 'f-001');

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('f-001', $body['funnel_id']);
        self::assertEquals(60.0, $body['overall_conversion_rate']);
        self::assertCount(2, $body['steps']);
        self::assertEquals(40.0, $body['steps'][1]['drop_off_rate']);
    }

    #[Test]
    public function evaluateReturns404WhenFunnelNotFound(): void
    {
        $this->funnelService->method('evaluate')->willThrowException(
            AnalyticsException::notFound('Funnel', 'missing'),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/funnels/missing/evaluate',
        );

        $response = $this->controller->evaluate($request, 'missing');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function evaluateUsesDefaultDateRange(): void
    {
        $result = new FunnelResult('f-001', [], 0.0);
        $this->funnelService->method('evaluate')->willReturn($result);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/funnels/f-001/evaluate',
        );

        $response = $this->controller->evaluate($request, 'f-001');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturnsSuccessResponse(): void
    {
        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/plsr/api/v1/funnels/f-001',
        );

        $response = $this->controller->delete($request, 'f-001');

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('f-001', $body['id']);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function deleteReturns404WhenFunnelNotFound(): void
    {
        $this->funnelService->method('delete')->willThrowException(
            AnalyticsException::notFound('Funnel', 'missing'),
        );

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/plsr/api/v1/funnels/missing',
        );

        $response = $this->controller->delete($request, 'missing');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function createSkipsNonArraySteps(): void
    {
        $this->funnelService->method('create')->willReturn(
            new FunnelDefinition(
                id: 'f-new',
                siteId: 'site-001',
                name: 'Test',
                steps: [new FunnelStep(1, 'Home', FunnelStepType::PageVisit, '/')],
                createdAt: new DateTimeImmutable('2025-06-01'),
            ),
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/plsr/api/v1/funnels',
            parsedBody: [
                'site_id' => 'site-001',
                'name' => 'Test',
                'steps' => [
                    'not-an-array',
                    ['name' => 'Home', 'type' => 'page_visit', 'value' => '/'],
                ],
            ],
        );

        $response = $this->controller->create($request);

        self::assertSame(201, $response->getStatusCode());
    }
}
