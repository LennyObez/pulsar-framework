<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\FlowServiceInterface;
use Pulsar\Extension\Analytics\Domain\FlowStep;
use Pulsar\Extension\Analytics\Server\Controller\FlowController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class FlowControllerTest extends TestCase
{
    private FlowController $controller;
    private FlowServiceInterface&Stub $flowService;

    protected function setUp(): void
    {
        $this->flowService = $this->createStub(FlowServiceInterface::class);
        $this->controller = new FlowController($this->flowService);
    }

    #[Test]
    public function flowReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/flow');

        $response = $this->controller->flow($request);

        self::assertSame(400, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('site_id is required', $body['error']);
    }

    #[Test]
    public function flowReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/flow',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->flow($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function flowReturnsBadRequestWhenSiteIdNonString(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/flow',
            queryParams: ['site_id' => ['invalid']],
        );

        $response = $this->controller->flow($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function flowReturnsEmptyDataWhenNoFlowPaths(): void
    {
        $this->flowService->method('getFlowFromPage')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/flow',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->flow($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
    }

    #[Test]
    public function flowReturnsFlowSteps(): void
    {
        $steps = [
            new FlowStep('/', '/about', 150, 0),
            new FlowStep('/', '/pricing', 80, 0),
        ];
        $this->flowService->method('getFlowFromPage')->willReturn($steps);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/flow',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->flow($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame('/', $body['data'][0]['source']);
        self::assertSame('/about', $body['data'][0]['target']);
        self::assertSame(150, $body['data'][0]['visitors']);
        self::assertSame(0, $body['data'][0]['depth']);
    }

    #[Test]
    public function flowPassesEntryPageAndDepthParameters(): void
    {
        $service = $this->createMock(FlowServiceInterface::class);
        $service->expects(self::once())
            ->method('getFlowFromPage')
            ->with('site-001', self::anything(), self::anything(), '/products', 4)
            ->willReturn([]);

        $controller = new FlowController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/flow',
            queryParams: [
                'site_id' => 'site-001',
                'entry_page' => '/products',
                'depth' => '4',
            ],
        );

        $response = $controller->flow($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function flowClampsDepthBetween1And5(): void
    {
        $service = $this->createMock(FlowServiceInterface::class);
        $service->expects(self::once())
            ->method('getFlowFromPage')
            ->with('site-001', self::anything(), self::anything(), '/', 5)
            ->willReturn([]);

        $controller = new FlowController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/flow',
            queryParams: ['site_id' => 'site-001', 'depth' => '99'],
        );

        $response = $controller->flow($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function flowDefaultsToSlashEntryPage(): void
    {
        $service = $this->createMock(FlowServiceInterface::class);
        $service->expects(self::once())
            ->method('getFlowFromPage')
            ->with('site-001', self::anything(), self::anything(), '/', 3)
            ->willReturn([]);

        $controller = new FlowController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/flow',
            queryParams: ['site_id' => 'site-001'],
        );

        $controller->flow($request);
    }

    #[Test]
    public function exitsReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/flow/exits');

        $response = $this->controller->exits($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function exitsReturnsExitPages(): void
    {
        $exitData = [
            ['pathname' => '/checkout', 'exits' => 50, 'exit_rate' => 35.0],
            ['pathname' => '/contact', 'exits' => 30, 'exit_rate' => 21.0],
        ];
        $this->flowService->method('getExitPages')->willReturn($exitData);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/flow/exits',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->exits($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame('/checkout', $body['data'][0]['pathname']);
    }

    #[Test]
    public function exitsUsesDefaultDateRange(): void
    {
        $this->flowService->method('getExitPages')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/flow/exits',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->exits($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
