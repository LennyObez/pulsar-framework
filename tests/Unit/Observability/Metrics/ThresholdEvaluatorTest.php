<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\AlertFiring;
use Pulsar\Observability\Metrics\AlertThreshold;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Metrics\ThresholdEvaluator;

#[CoversClass(ThresholdEvaluator::class)]
final class ThresholdEvaluatorTest extends TestCase
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
    public function evaluateDoesNotFireWhenMetricDoesNotExist(): void
    {
        $registry = new MetricRegistry();
        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold('nonexistent', 100.0, 'gt', 0));

        $fired = $evaluator->evaluate();

        self::assertSame([], $fired);
    }

    #[Test]
    public function evaluateFiresWhenCounterExceedsThresholdImmediately(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('http_errors');
        $counter->increment(value: 10.0);

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold('http_errors', 5.0, 'gt', 0));

        $fired = $evaluator->evaluate();

        self::assertCount(1, $fired);
        self::assertSame(10.0, $fired[0]->actualValue);
    }

    #[Test]
    public function evaluateFiresWhenGaugeExceedsThreshold(): void
    {
        $registry = new MetricRegistry();
        $gauge = $registry->gauge('cpu_usage');
        $gauge->set(95.0);

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold('cpu_usage', 90.0, 'gt', 0));

        $fired = $evaluator->evaluate();

        self::assertCount(1, $fired);
        self::assertSame(95.0, $fired[0]->actualValue);
    }

    #[Test]
    public function evaluateDoesNotFireWhenConditionIsNotSatisfied(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('http_errors');
        $counter->increment(value: 3.0);

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold('http_errors', 5.0, 'gt', 0));

        $fired = $evaluator->evaluate();

        self::assertSame([], $fired);
    }

    #[Test]
    public function evaluateDoesNotFireForHistogramMetrics(): void
    {
        $registry = new MetricRegistry();
        $registry->histogram('request_duration');

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold('request_duration', 100.0, 'gt', 0));

        $fired = $evaluator->evaluate();

        self::assertSame([], $fired);
    }

    #[Test]
    public function onFireCallbackIsInvokedWhenAlertFires(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('errors');
        $counter->increment(value: 100.0);

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold('errors', 50.0, 'gt', 0));

        $captured = [];
        $evaluator->onFire(function (AlertFiring $firing) use (&$captured): void {
            $captured[] = $firing;
        });

        $evaluator->evaluate();

        self::assertCount(1, $captured);
        self::assertSame(100.0, $captured[0]->actualValue);
    }

    #[Test]
    public function firingsReturnsAllHistoricalFirings(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('errors');
        $counter->increment(value: 100.0);

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold('errors', 50.0, 'gt', 0));

        $evaluator->evaluate();

        self::assertCount(1, $evaluator->firings());
    }

    #[Test]
    public function thresholdsReturnsRegisteredThresholds(): void
    {
        $registry = new MetricRegistry();
        $evaluator = new ThresholdEvaluator($registry);

        $t1 = new AlertThreshold('cpu', 90.0, 'gt', 60);
        $t2 = new AlertThreshold('memory', 80.0, 'gt', 120);

        $evaluator->addThreshold($t1);
        $evaluator->addThreshold($t2);

        self::assertCount(2, $evaluator->thresholds());
        self::assertSame($t1, $evaluator->thresholds()[0]);
        self::assertSame($t2, $evaluator->thresholds()[1]);
    }

    #[Test]
    public function resetClearsFiringsAndConditionTimers(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('errors');
        $counter->increment(value: 100.0);

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold('errors', 50.0, 'gt', 0));

        $evaluator->evaluate();
        self::assertCount(1, $evaluator->firings());

        $evaluator->reset();
        self::assertCount(0, $evaluator->firings());
    }

    #[Test]
    public function conditionResetWhenNoLongerSatisfied(): void
    {
        $registry = new MetricRegistry();
        $gauge = $registry->gauge('cpu');
        $gauge->set(95.0);

        $evaluator = new ThresholdEvaluator($registry);
        // forSeconds > 0 means condition must persist
        $evaluator->addThreshold(new AlertThreshold('cpu', 90.0, 'gt', 3600));

        // First evaluation starts the timer
        $evaluator->evaluate();
        self::assertCount(0, $evaluator->firings());

        // Drop below threshold — timer should reset
        $gauge->set(50.0);
        $evaluator->evaluate();

        // Raise again — timer restarts from zero, so still shouldn't fire
        $gauge->set(95.0);
        $evaluator->evaluate();

        self::assertCount(0, $evaluator->firings());
    }

    #[Test]
    public function firingResetsConditionTimerToPreventDuplicates(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('errors');
        $counter->increment(value: 100.0);

        $evaluator = new ThresholdEvaluator($registry);
        $evaluator->addThreshold(new AlertThreshold('errors', 50.0, 'gt', 0));

        $evaluator->evaluate();
        self::assertCount(1, $evaluator->firings());

        // Second evaluation should fire again (timer was reset after first firing)
        $evaluator->evaluate();
        self::assertCount(2, $evaluator->firings());
    }
}
