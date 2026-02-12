<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\ABTest;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\ABTest\Experiment;
use Pulsar\Extension\Cms\ABTest\ExperimentRepositoryInterface;
use Pulsar\Extension\Cms\ABTest\ExperimentStatus;
use Pulsar\Extension\Cms\ABTest\ExperimentVariant;
use Pulsar\Extension\Cms\Http\Controller\Admin\ExperimentController;
use Pulsar\Extension\Cms\Internal\ABTest\ExperimentService;
use Pulsar\Extension\Cms\Internal\ABTest\TrafficSplitter;

use function json_decode;

#[CoversClass(ExperimentController::class)]
final class ExperimentControllerTest extends TestCase
{
    private ExperimentRepositoryInterface&Stub $repository;
    private ExperimentService $experimentService;
    private ExperimentController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(ExperimentRepositoryInterface::class);
        $this->experimentService = new ExperimentService($this->repository, new TrafficSplitter());

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $this->controller = new ExperimentController($this->experimentService, $gate);
    }

    #[Test]
    public function index_returns_running_experiments(): void
    {
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'Test',
            contentId: 'c-1',
            status: ExperimentStatus::Running,
            trafficPercentage: 1.0,
            startAt: new DateTimeImmutable(),
            endAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $this->repository->method('findRunning')->willReturn([$experiment]);

        $request = $this->createAuthenticatedRequest();

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['experiments']);
        self::assertCount(1, $body['experiments']);
        self::assertIsArray($body['experiments'][0]);
        self::assertSame('exp-1', $body['experiments'][0]['id']);
    }

    #[Test]
    public function create_returns_experiment_with_variants(): void
    {
        $request = $this->createAuthenticatedRequest([
            'name' => 'CTA Color Test',
            'content_id' => 'content-1',
            'traffic_percentage' => 0.5,
            'variants' => [
                ['name' => 'Red CTA', 'content_id' => 'content-red', 'weight' => 50],
                ['name' => 'Blue CTA', 'content_id' => 'content-blue', 'weight' => 50],
            ],
        ]);

        // The service will call save → we need findById to return the experiment after create
        // Since createExperiment generates an ID internally, we stub findById to return the draft
        $this->repository->method('findById')->willReturnCallback(
            fn(string $id) => new Experiment(
                id: $id,
                name: 'CTA Color Test',
                contentId: 'content-1',
                status: ExperimentStatus::Draft,
                trafficPercentage: 0.5,
                startAt: null,
                endAt: null,
                createdAt: new DateTimeImmutable(),
            ),
        );

        $response = $this->controller->create($request);

        self::assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('id', $body);
        self::assertSame('draft', $body['status']);
        self::assertIsArray($body['variants']);
        self::assertCount(2, $body['variants']);
    }

    #[Test]
    public function create_with_missing_fields_returns_400(): void
    {
        $request = $this->createAuthenticatedRequest([
            'name' => '',
            'content_id' => '',
        ]);

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function show_returns_experiment_details(): void
    {
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'Test',
            contentId: 'c-1',
            status: ExperimentStatus::Draft,
            trafficPercentage: 1.0,
            startAt: null,
            endAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $variants = [
            new ExperimentVariant('v1', 'exp-1', 'A', 'ca', 50),
            new ExperimentVariant('v2', 'exp-1', 'B', 'cb', 50),
        ];

        $this->repository->method('findById')->willReturn($experiment);
        $this->repository->method('findVariants')->willReturn($variants);

        $request = $this->createAuthenticatedRequest();

        $response = $this->controller->show($request, 'exp-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['experiment']);
        self::assertSame('exp-1', $body['experiment']['id']);
        self::assertIsArray($body['variants']);
        self::assertCount(2, $body['variants']);
    }

    #[Test]
    public function show_not_found_returns_404(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $request = $this->createAuthenticatedRequest();

        $response = $this->controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function start_transitions_to_running(): void
    {
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'Test',
            contentId: 'c-1',
            status: ExperimentStatus::Draft,
            trafficPercentage: 1.0,
            startAt: null,
            endAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $variants = [
            new ExperimentVariant('v1', 'exp-1', 'A', 'ca', 50),
            new ExperimentVariant('v2', 'exp-1', 'B', 'cb', 50),
        ];

        $this->repository->method('findById')->willReturn($experiment);
        $this->repository->method('findVariants')->willReturn($variants);

        $request = $this->createAuthenticatedRequest();

        $response = $this->controller->start($request, 'exp-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('running', $body['status']);
    }

    #[Test]
    public function stop_transitions_to_completed(): void
    {
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'Test',
            contentId: 'c-1',
            status: ExperimentStatus::Running,
            trafficPercentage: 1.0,
            startAt: new DateTimeImmutable(),
            endAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $this->repository->method('findById')->willReturn($experiment);

        $request = $this->createAuthenticatedRequest();

        $response = $this->controller->stop($request, 'exp-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('completed', $body['status']);
    }

    #[Test]
    public function results_returns_experiment_results(): void
    {
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'Test',
            contentId: 'c-1',
            status: ExperimentStatus::Completed,
            trafficPercentage: 1.0,
            startAt: new DateTimeImmutable('-1 day'),
            endAt: new DateTimeImmutable(),
            createdAt: new DateTimeImmutable('-2 days'),
        );

        $variants = [
            new ExperimentVariant('v1', 'exp-1', 'A', 'ca', 50),
            new ExperimentVariant('v2', 'exp-1', 'B', 'cb', 50),
        ];

        $counts = [
            'v1' => ['impressions' => 500, 'conversions' => 50],
            'v2' => ['impressions' => 500, 'conversions' => 75],
        ];

        $this->repository->method('findById')->willReturn($experiment);
        $this->repository->method('findVariants')->willReturn($variants);
        $this->repository->method('getConversionCounts')->willReturn($counts);

        $request = $this->createAuthenticatedRequest();

        $response = $this->controller->results($request, 'exp-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('exp-1', $body['experiment_id']);
        self::assertIsArray($body['results']);
        self::assertCount(2, $body['results']);
        self::assertIsArray($body['results'][0]);
        self::assertSame(50, $body['results'][0]['conversions']);
        self::assertIsArray($body['results'][1]);
        self::assertSame(75, $body['results'][1]['conversions']);
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(?array $parsedBody = null): ServerRequestInterface&Stub
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('user-1');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnMap([
            ['identity', null, $identity],
            ['step_up_verified', false, false],
            ['tenant_id', null, null],
        ]);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getParsedBody')->willReturn($parsedBody);

        return $request;
    }
}
