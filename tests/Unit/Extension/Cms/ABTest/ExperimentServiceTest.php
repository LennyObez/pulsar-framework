<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\ABTest;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\ABTest\Experiment;
use Pulsar\Extension\Cms\ABTest\ExperimentRepositoryInterface;
use Pulsar\Extension\Cms\ABTest\ExperimentStatus;
use Pulsar\Extension\Cms\ABTest\ExperimentVariant;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\ABTest\ExperimentService;
use Pulsar\Extension\Cms\Internal\ABTest\TrafficSplitter;

#[CoversClass(ExperimentService::class)]
final class ExperimentServiceTest extends TestCase
{
    private ExperimentRepositoryInterface&Stub $repository;
    private ExperimentService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(ExperimentRepositoryInterface::class);
        $this->service = new ExperimentService($this->repository, new TrafficSplitter());
    }

    #[Test]
    public function create_experiment_returns_draft(): void
    {
        $experiment = $this->service->createExperiment('Homepage Test', 'content-1', 0.5);

        self::assertSame('Homepage Test', $experiment->name);
        self::assertSame('content-1', $experiment->contentId);
        self::assertSame(ExperimentStatus::Draft, $experiment->status);
        self::assertSame(0.5, $experiment->trafficPercentage);
        self::assertNull($experiment->startAt);
        self::assertNull($experiment->endAt);
        self::assertNotEmpty($experiment->id);
    }

    #[Test]
    public function create_experiment_clamps_traffic_percentage(): void
    {
        $low = $this->service->createExperiment('Low', 'c1', -0.5);
        self::assertSame(0.0, $low->trafficPercentage);

        $high = $this->service->createExperiment('High', 'c2', 1.5);
        self::assertSame(1.0, $high->trafficPercentage);
    }

    #[Test]
    public function add_variant_to_draft_experiment(): void
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

        $this->repository->method('findById')->willReturn($experiment);

        $variant = $this->service->addVariant('exp-1', 'Variant A', 'content-a', 50);

        self::assertSame('exp-1', $variant->experimentId);
        self::assertSame('Variant A', $variant->name);
        self::assertSame('content-a', $variant->contentId);
        self::assertSame(50, $variant->weight);
    }

    #[Test]
    public function add_variant_to_running_experiment_throws(): void
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

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Cannot add variants to a non-draft experiment');
        $this->service->addVariant('exp-1', 'Variant', 'content', 50);
    }

    #[Test]
    public function start_experiment_with_two_variants(): void
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

        $started = $this->service->startExperiment('exp-1');

        self::assertSame(ExperimentStatus::Running, $started->status);
        self::assertNotNull($started->startAt);
    }

    #[Test]
    public function start_experiment_with_insufficient_variants_throws(): void
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

        $this->repository->method('findById')->willReturn($experiment);
        $this->repository->method('findVariants')->willReturn([
            new ExperimentVariant('v1', 'exp-1', 'Only', 'ca', 100),
        ]);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('at least 2 variants');
        $this->service->startExperiment('exp-1');
    }

    #[Test]
    public function stop_running_experiment(): void
    {
        $experiment = new Experiment(
            id: 'exp-1',
            name: 'Test',
            contentId: 'c-1',
            status: ExperimentStatus::Running,
            trafficPercentage: 1.0,
            startAt: new DateTimeImmutable('-1 hour'),
            endAt: null,
            createdAt: new DateTimeImmutable('-2 hours'),
        );

        $this->repository->method('findById')->willReturn($experiment);

        $stopped = $this->service->stopExperiment('exp-1');

        self::assertSame(ExperimentStatus::Completed, $stopped->status);
        self::assertNotNull($stopped->endAt);
    }

    #[Test]
    public function stop_non_running_experiment_throws(): void
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

        $this->repository->method('findById')->willReturn($experiment);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Only running experiments can be stopped');
        $this->service->stopExperiment('exp-1');
    }

    #[Test]
    public function select_variant_for_visitor_returns_null_when_no_experiment(): void
    {
        $this->repository->method('findByContentId')->willReturn(null);

        $result = $this->service->selectVariantForVisitor('content-1', 'visitor-1');

        self::assertNull($result);
    }

    #[Test]
    public function select_variant_for_visitor_with_active_experiment(): void
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

        $variants = [
            new ExperimentVariant('v1', 'exp-1', 'A', 'ca', 50),
            new ExperimentVariant('v2', 'exp-1', 'B', 'cb', 50),
        ];

        $this->repository->method('findByContentId')->willReturn($experiment);
        $this->repository->method('findVariants')->willReturn($variants);

        $result = $this->service->selectVariantForVisitor('c-1', 'visitor-1');

        self::assertNotNull($result);
        self::assertContains($result->id, ['v1', 'v2']);
    }

    #[Test]
    public function get_results_with_conversion_data(): void
    {
        $variants = [
            new ExperimentVariant('v1', 'exp-1', 'A', 'ca', 50),
            new ExperimentVariant('v2', 'exp-1', 'B', 'cb', 50),
        ];

        $counts = [
            'v1' => ['impressions' => 100, 'conversions' => 10],
            'v2' => ['impressions' => 100, 'conversions' => 20],
        ];

        $this->repository->method('findVariants')->willReturn($variants);
        $this->repository->method('getConversionCounts')->willReturn($counts);

        $results = $this->service->getResults('exp-1');

        self::assertCount(2, $results);

        $resultA = $results[0];
        self::assertSame('v1', $resultA->variantId);
        self::assertSame(100, $resultA->impressions);
        self::assertSame(10, $resultA->conversions);
        self::assertEqualsWithDelta(0.1, $resultA->conversionRate, 0.001);
        self::assertGreaterThan(0.0, $resultA->confidenceLevel);
        self::assertLessThanOrEqual(1.0, $resultA->confidenceLevel);

        $resultB = $results[1];
        self::assertSame('v2', $resultB->variantId);
        self::assertSame(20, $resultB->conversions);
        self::assertEqualsWithDelta(0.2, $resultB->conversionRate, 0.001);
    }

    #[Test]
    public function get_results_with_no_data(): void
    {
        $variants = [
            new ExperimentVariant('v1', 'exp-1', 'A', 'ca', 50),
        ];

        $this->repository->method('findVariants')->willReturn($variants);
        $this->repository->method('getConversionCounts')->willReturn([]);

        $results = $this->service->getResults('exp-1');

        self::assertCount(1, $results);
        self::assertSame(0, $results[0]->impressions);
        self::assertSame(0, $results[0]->conversions);
        self::assertSame(0.0, $results[0]->conversionRate);
        self::assertSame(0.0, $results[0]->confidenceLevel);
    }

    #[Test]
    public function start_nonexistent_experiment_throws(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->service->startExperiment('nonexistent');
    }
}
