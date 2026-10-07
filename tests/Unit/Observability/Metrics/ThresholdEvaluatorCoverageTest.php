<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\AlertFiring;
use Pulsar\Observability\Metrics\AlertThreshold;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Metrics\ThresholdEvaluator;

#[CoversClass(ThresholdEvaluator::class)]
#[CoversClass(AlertThreshold::class)]
#[CoversClass(AlertFiring::class)]
final class ThresholdEvaluatorCoverageTest extends TestCase
{
    #[Test]
    public function evaluateReturnsEmptyWhenNoThresholds(): void
    {
        $registry = new MetricRegistry();
        $evaluator = new ThresholdEvaluator($registry);

        $fired = $evaluator->evaluate();

        self::assertSame([], $fired);
    }

    #[Test]
    public function evaluateReturnsEmptyWhenMetricNotRegistered(): void
    {
        $registry = new MetricRegistry();
        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold(
            metricName: 'nonexistent',
            value: 100.0,
            comparator: 'gt',
            forSeconds: 0,
        ));

        $fired = $evaluator->evaluate();

        self::assertSame([], $fired);
    }

    #[Test]
    public function evaluateFiresImmediatelyWhenForSecondsIsZero(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('http_errors', 'Error count');
        $counter->increment();
        $counter->increment();
        $counter->increment();

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold(
            metricName: 'http_errors',
            value: 2.0,
            comparator: 'gt',
            forSeconds: 0,
            description: 'Error rate too high',
        ));

        $fired = $evaluator->evaluate();

        self::assertCount(1, $fired);
        self::assertSame('http_errors', $fired[0]->threshold->metricName);
        self::assertSame(3.0, $fired[0]->actualValue);
    }

    #[Test]
    public function evaluateDoesNotFireWhenConditionNotMet(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('requests', 'Request count');
        $counter->increment();

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold(
            metricName: 'requests',
            value: 100.0,
            comparator: 'gt',
            forSeconds: 0,
        ));

        $fired = $evaluator->evaluate();

        self::assertSame([], $fired);
    }

    #[Test]
    public function onFireCallbackInvokedWhenAlertFires(): void
    {
        $registry = new MetricRegistry();
        $gauge = $registry->gauge('cpu_usage', 'CPU usage');
        $gauge->set(95.0);

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold(
            metricName: 'cpu_usage',
            value: 90.0,
            comparator: 'gte',
            forSeconds: 0,
        ));

        $callbackFired = null;
        $evaluator->onFire(function (AlertFiring $firing) use (&$callbackFired): void {
            $callbackFired = $firing;
        });

        $evaluator->evaluate();

        self::assertNotNull($callbackFired);
        self::assertSame(95.0, $callbackFired->actualValue);
    }

    #[Test]
    public function firingsAccumulatesHistory(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('errors', 'Errors');
        $counter->increment();
        $counter->increment();
        $counter->increment();

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold(
            metricName: 'errors',
            value: 0.0,
            comparator: 'gt',
            forSeconds: 0,
        ));

        self::assertSame([], $evaluator->firings());

        $evaluator->evaluate();
        self::assertCount(1, $evaluator->firings());

        // Second evaluation should also fire (value still above threshold)
        $evaluator->evaluate();
        self::assertCount(2, $evaluator->firings());
    }

    #[Test]
    public function resetClearsAllState(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('metric', 'Test');
        $counter->increment();

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold(
            metricName: 'metric',
            value: 0.0,
            comparator: 'gt',
            forSeconds: 0,
        ));

        $evaluator->evaluate();
        self::assertCount(1, $evaluator->firings());

        $evaluator->reset();
        self::assertSame([], $evaluator->firings());
    }

    #[Test]
    public function thresholdsReturnsRegistered(): void
    {
        $registry = new MetricRegistry();
        $evaluator = new ThresholdEvaluator($registry);

        self::assertSame([], $evaluator->thresholds());

        $t1 = new AlertThreshold('m1', 100.0, 'gt', 0);
        $t2 = new AlertThreshold('m2', 50.0, 'lt', 0);

        $evaluator->addThreshold($t1);
        $evaluator->addThreshold($t2);

        self::assertCount(2, $evaluator->thresholds());
        self::assertSame($t1, $evaluator->thresholds()[0]);
        self::assertSame($t2, $evaluator->thresholds()[1]);
    }

    // ── AlertThreshold comparator tests ─────────────────────────────────

    #[Test]
    #[DataProvider('comparatorProvider')]
    public function alertThresholdIsSatisfiedBy(
        string $comparator,
        float $threshold,
        float $actual,
        bool $expected,
    ): void {
        $at = new AlertThreshold('metric', $threshold, $comparator, 0);

        self::assertSame($expected, $at->isSatisfiedBy($actual));
    }

    /**
     * @return iterable<string, array{string, float, float, bool}>
     */
    public static function comparatorProvider(): iterable
    {
        yield 'gt satisfied' => ['gt', 10.0, 11.0, true];
        yield 'gt not satisfied (equal)' => ['gt', 10.0, 10.0, false];
        yield 'gt not satisfied (less)' => ['gt', 10.0, 9.0, false];

        yield 'gte satisfied (greater)' => ['gte', 10.0, 11.0, true];
        yield 'gte satisfied (equal)' => ['gte', 10.0, 10.0, true];
        yield 'gte not satisfied' => ['gte', 10.0, 9.0, false];

        yield 'lt satisfied' => ['lt', 10.0, 9.0, true];
        yield 'lt not satisfied (equal)' => ['lt', 10.0, 10.0, false];
        yield 'lt not satisfied (greater)' => ['lt', 10.0, 11.0, false];

        yield 'lte satisfied (less)' => ['lte', 10.0, 9.0, true];
        yield 'lte satisfied (equal)' => ['lte', 10.0, 10.0, true];
        yield 'lte not satisfied' => ['lte', 10.0, 11.0, false];

        yield 'eq satisfied' => ['eq', 10.0, 10.0, true];
        yield 'eq not satisfied' => ['eq', 10.0, 10.1, false];

        yield 'unknown comparator returns false' => ['unknown', 10.0, 10.0, false];
    }

    #[Test]
    public function alertFiringFromFactory(): void
    {
        $threshold = new AlertThreshold(
            metricName: 'memory_usage',
            value: 80.0,
            comparator: 'gt',
            forSeconds: 60,
            description: 'Memory too high',
        );

        $firing = AlertFiring::from($threshold, 92.5);

        self::assertSame($threshold, $firing->threshold);
        self::assertSame(92.5, $firing->actualValue);
        self::assertGreaterThan(0.0, $firing->firedAt);
    }

    #[Test]
    public function alertFiringConstructor(): void
    {
        $threshold = new AlertThreshold('test', 50.0, 'gt', 0);
        $firing = new AlertFiring($threshold, 75.0, 1234567890.123);

        self::assertSame($threshold, $firing->threshold);
        self::assertSame(75.0, $firing->actualValue);
        self::assertSame(1234567890.123, $firing->firedAt);
    }

    #[Test]
    public function evaluateWithGaugeMetric(): void
    {
        $registry = new MetricRegistry();
        $gauge = $registry->gauge('disk_free_mb', 'Free disk space');
        $gauge->set(50.0);

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold(
            metricName: 'disk_free_mb',
            value: 100.0,
            comparator: 'lt',
            forSeconds: 0,
        ));

        $fired = $evaluator->evaluate();

        self::assertCount(1, $fired);
        self::assertSame(50.0, $fired[0]->actualValue);
    }

    #[Test]
    public function conditionResetsWhenNoLongerSatisfied(): void
    {
        $registry = new MetricRegistry();
        $gauge = $registry->gauge('cpu', 'CPU usage');
        $gauge->set(95.0);

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold(
            metricName: 'cpu',
            value: 90.0,
            comparator: 'gt',
            forSeconds: 3600, // Very long, won't fire in test
        ));

        $evaluator->evaluate(); // Start tracking

        // Bring value below threshold
        $gauge->set(50.0);
        $evaluator->evaluate(); // Should reset condition timer

        self::assertSame([], $evaluator->firings());
    }
}
