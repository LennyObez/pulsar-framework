<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\ABTest\Experiment;
use Pulsar\Extension\Cms\ABTest\ExperimentRepositoryInterface;
use Pulsar\Extension\Cms\ABTest\ExperimentStatus;
use Pulsar\Extension\Cms\ABTest\ExperimentVariant;
use Pulsar\Extension\Cms\Http\Controller\Admin\ExperimentController;
use Pulsar\Extension\Cms\Internal\ABTest\ExperimentService;
use Pulsar\Extension\Cms\Internal\ABTest\TrafficSplitter;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ExperimentController::class)]
final class ExperimentControllerTest extends TestCase
{
    #[Test]
    public function index_returns_running_experiments(): void
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'CTA Button Color',
            contentId: 'content-1',
            status: ExperimentStatus::Running,
            trafficPercentage: 0.5,
            startAt: $now,
            endAt: null,
            createdAt: $now,
        );

        $repo = $this->createStub(ExperimentRepositoryInterface::class);
        $repo->method('findRunning')->willReturn([$experiment]);

        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $controller = new ExperimentController(experimentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $experiments */
        $experiments = $body['experiments'];
        self::assertCount(1, $experiments);
        self::assertSame('exp-1', $experiments[0]['id']);
        self::assertSame('CTA Button Color', $experiments[0]['name']);
        self::assertSame('running', $experiments[0]['status']);
    }

    #[Test]
    public function create_returns_new_experiment(): void
    {
        $repo = $this->createStub(ExperimentRepositoryInterface::class);

        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $controller = new ExperimentController(experimentService: $service);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'name' => 'Homepage Hero',
            'content_id' => 'content-1',
            'traffic_percentage' => 0.5,
        ]);

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['id']);
        self::assertSame('draft', $body['status']);
    }

    #[Test]
    public function create_returns_400_when_name_missing(): void
    {
        $repo = $this->createStub(ExperimentRepositoryInterface::class);

        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $controller = new ExperimentController(experimentService: $service);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'name' => '',
            'content_id' => 'content-1',
        ]);

        $response = $controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function show_returns_experiment_with_variants(): void
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'Hero Test',
            contentId: 'content-1',
            status: ExperimentStatus::Draft,
            trafficPercentage: 1.0,
            startAt: null,
            endAt: null,
            createdAt: $now,
        );
        $variant = new ExperimentVariant(
            id: 'var-1',
            experimentId: 'exp-1',
            name: 'Variant A',
            contentId: 'content-1a',
            weight: 1,
        );

        $repo = $this->createStub(ExperimentRepositoryInterface::class);
        $repo->method('findById')->willReturn($experiment);
        $repo->method('findVariants')->willReturn([$variant]);

        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $controller = new ExperimentController(experimentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'exp-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $exp */
        $exp = $body['experiment'];
        self::assertSame('exp-1', $exp['id']);

        /** @var list<array<string, mixed>> $variants */
        $variants = $body['variants'];
        self::assertCount(1, $variants);
        self::assertSame('var-1', $variants[0]['id']);
    }

    #[Test]
    public function show_returns_404_when_experiment_not_found(): void
    {
        $repo = $this->createStub(ExperimentRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $controller = new ExperimentController(experimentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function start_returns_started_experiment(): void
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'Hero Test',
            contentId: 'content-1',
            status: ExperimentStatus::Draft,
            trafficPercentage: 1.0,
            startAt: null,
            endAt: null,
            createdAt: $now,
        );

        $variantA = new ExperimentVariant('var-1', 'exp-1', 'A', 'c-1a', 1);
        $variantB = new ExperimentVariant('var-2', 'exp-1', 'B', 'c-1b', 1);

        $repo = $this->createStub(ExperimentRepositoryInterface::class);
        $repo->method('findById')->willReturn($experiment);
        $repo->method('findVariants')->willReturn([$variantA, $variantB]);

        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $controller = new ExperimentController(experimentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->start($request, 'exp-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('exp-1', $body['id']);
        self::assertSame('running', $body['status']);
        self::assertIsString($body['start_at']);
    }

    #[Test]
    public function start_returns_422_when_experiment_cannot_start(): void
    {
        $now = new DateTimeImmutable();
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'Test',
            contentId: 'content-1',
            status: ExperimentStatus::Running,
            trafficPercentage: 1.0,
            startAt: $now,
            endAt: null,
            createdAt: $now,
        );

        $repo = $this->createStub(ExperimentRepositoryInterface::class);
        $repo->method('findById')->willReturn($experiment);

        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $controller = new ExperimentController(experimentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->start($request, 'exp-1');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function stop_returns_stopped_experiment(): void
    {
        $now = new DateTimeImmutable();
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'Test',
            contentId: 'content-1',
            status: ExperimentStatus::Running,
            trafficPercentage: 1.0,
            startAt: $now,
            endAt: null,
            createdAt: $now,
        );

        $repo = $this->createStub(ExperimentRepositoryInterface::class);
        $repo->method('findById')->willReturn($experiment);

        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $controller = new ExperimentController(experimentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->stop($request, 'exp-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('completed', $body['status']);
    }

    #[Test]
    public function results_returns_experiment_results(): void
    {
        $now = new DateTimeImmutable();
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'Test',
            contentId: 'content-1',
            status: ExperimentStatus::Completed,
            trafficPercentage: 1.0,
            startAt: $now,
            endAt: $now,
            createdAt: $now,
        );

        $variant = new ExperimentVariant('var-1', 'exp-1', 'A', 'c-1a', 1);

        $repo = $this->createStub(ExperimentRepositoryInterface::class);
        $repo->method('findById')->willReturn($experiment);
        $repo->method('findVariants')->willReturn([$variant]);
        $repo->method('getConversionCounts')->willReturn([
            'var-1' => ['impressions' => 100, 'conversions' => 15],
        ]);

        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $controller = new ExperimentController(experimentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->results($request, 'exp-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('exp-1', $body['experiment_id']);

        /** @var list<array<string, mixed>> $results */
        $results = $body['results'];
        self::assertCount(1, $results);
        self::assertSame('var-1', $results[0]['variant_id']);
        self::assertSame(100, $results[0]['impressions']);
        self::assertSame(15, $results[0]['conversions']);
    }

    #[Test]
    public function results_returns_404_when_experiment_not_found(): void
    {
        $repo = $this->createStub(ExperimentRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $controller = new ExperimentController(experimentService: $service);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->results($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $repo = $this->createStub(ExperimentRepositoryInterface::class);
        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $controller = new ExperimentController(experimentService: $service);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $repo = $this->createStub(ExperimentRepositoryInterface::class);
        $service = new ExperimentService(repository: $repo, splitter: new TrafficSplitter());
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new ExperimentController(experimentService: $service, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(?array $parsedBody = null): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/experiments');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/experiments');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
