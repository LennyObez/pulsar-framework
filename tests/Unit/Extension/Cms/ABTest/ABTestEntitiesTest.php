<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\ABTest;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\ABTest\ConversionEvent;
use Pulsar\Extension\Cms\ABTest\Experiment;
use Pulsar\Extension\Cms\ABTest\ExperimentResult;
use Pulsar\Extension\Cms\ABTest\ExperimentStatus;
use Pulsar\Extension\Cms\ABTest\ExperimentVariant;

#[CoversClass(ConversionEvent::class)]
#[CoversClass(Experiment::class)]
#[CoversClass(ExperimentResult::class)]
#[CoversClass(ExperimentStatus::class)]
#[CoversClass(ExperimentVariant::class)]
final class ABTestEntitiesTest extends TestCase
{
    // -- ExperimentStatus -----------------------------------------------------

    #[Test]
    public function experimentStatusValues(): void
    {
        self::assertSame('draft', ExperimentStatus::Draft->value);
        self::assertSame('running', ExperimentStatus::Running->value);
        self::assertSame('completed', ExperimentStatus::Completed->value);
        self::assertSame('cancelled', ExperimentStatus::Cancelled->value);
    }

    // -- Experiment -----------------------------------------------------------

    #[Test]
    public function experimentConstructor(): void
    {
        $now = new DateTimeImmutable('2025-03-07T09:00:00+00:00');

        $experiment = new Experiment(
            id: 'exp-01',
            name: 'Homepage Hero Test',
            contentId: 'cnt-01',
            status: ExperimentStatus::Draft,
            trafficPercentage: 50.0,
            startAt: null,
            endAt: null,
            createdAt: $now,
        );

        self::assertSame('exp-01', $experiment->id);
        self::assertSame('Homepage Hero Test', $experiment->name);
        self::assertSame(ExperimentStatus::Draft, $experiment->status);
        self::assertSame(50.0, $experiment->trafficPercentage);
        self::assertNull($experiment->startAt);
        self::assertNull($experiment->endAt);
    }

    #[Test]
    public function experimentStart(): void
    {
        $experiment = new Experiment(
            id: 'exp-01',
            name: 'CTA Color Test',
            contentId: 'cnt-01',
            status: ExperimentStatus::Draft,
            trafficPercentage: 100.0,
            startAt: null,
            endAt: null,
            createdAt: new DateTimeImmutable('-1 hour'),
        );

        $started = $experiment->start();

        self::assertSame(ExperimentStatus::Running, $started->status);
        self::assertNotNull($started->startAt);
        self::assertNull($started->endAt);
        self::assertSame('exp-01', $started->id);
    }

    #[Test]
    public function experimentStop(): void
    {
        $experiment = new Experiment(
            id: 'exp-02',
            name: 'Layout Test',
            contentId: 'cnt-02',
            status: ExperimentStatus::Running,
            trafficPercentage: 80.0,
            startAt: new DateTimeImmutable('-2 hours'),
            endAt: null,
            createdAt: new DateTimeImmutable('-3 hours'),
        );

        $stopped = $experiment->stop();

        self::assertSame(ExperimentStatus::Completed, $stopped->status);
        self::assertNotNull($stopped->endAt);
    }

    #[Test]
    public function experimentCancel(): void
    {
        $experiment = new Experiment(
            id: 'exp-03',
            name: 'Font Test',
            contentId: 'cnt-03',
            status: ExperimentStatus::Running,
            trafficPercentage: 25.0,
            startAt: new DateTimeImmutable('-1 hour'),
            endAt: null,
            createdAt: new DateTimeImmutable('-2 hours'),
        );

        $cancelled = $experiment->cancel();

        self::assertSame(ExperimentStatus::Cancelled, $cancelled->status);
        self::assertNotNull($cancelled->endAt);
    }

    #[Test]
    public function experimentIsRunning(): void
    {
        $running = new Experiment(
            id: 'exp-04',
            name: 'Active Test',
            contentId: 'cnt-04',
            status: ExperimentStatus::Running,
            trafficPercentage: 100.0,
            startAt: new DateTimeImmutable(),
            endAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $draft = new Experiment(
            id: 'exp-05',
            name: 'Draft Test',
            contentId: 'cnt-05',
            status: ExperimentStatus::Draft,
            trafficPercentage: 100.0,
            startAt: null,
            endAt: null,
            createdAt: new DateTimeImmutable(),
        );

        self::assertTrue($running->isRunning());
        self::assertFalse($draft->isRunning());
    }

    // -- ExperimentVariant ----------------------------------------------------

    #[Test]
    public function experimentVariantConstructor(): void
    {
        $variant = new ExperimentVariant(
            id: 'var-01',
            experimentId: 'exp-01',
            name: 'Control',
            contentId: 'cnt-01',
            weight: 50,
        );

        self::assertSame('var-01', $variant->id);
        self::assertSame('exp-01', $variant->experimentId);
        self::assertSame('Control', $variant->name);
        self::assertSame('cnt-01', $variant->contentId);
        self::assertSame(50, $variant->weight);
    }

    // -- ExperimentResult -----------------------------------------------------

    #[Test]
    public function experimentResultConstructor(): void
    {
        $result = new ExperimentResult(
            variantId: 'var-01',
            variantName: 'Control',
            impressions: 10_000,
            conversions: 450,
            conversionRate: 0.045,
            confidenceLevel: 0.95,
        );

        self::assertSame('var-01', $result->variantId);
        self::assertSame('Control', $result->variantName);
        self::assertSame(10_000, $result->impressions);
        self::assertSame(450, $result->conversions);
        self::assertSame(0.045, $result->conversionRate);
        self::assertSame(0.95, $result->confidenceLevel);
    }

    // -- ConversionEvent ------------------------------------------------------

    #[Test]
    public function conversionEventConstructor(): void
    {
        $now = new DateTimeImmutable();

        $event = new ConversionEvent(
            id: 'conv-01',
            experimentId: 'exp-01',
            variantId: 'var-01',
            visitorId: 'visitor-abc123',
            type: 'click',
            createdAt: $now,
        );

        self::assertSame('conv-01', $event->id);
        self::assertSame('exp-01', $event->experimentId);
        self::assertSame('var-01', $event->variantId);
        self::assertSame('visitor-abc123', $event->visitorId);
        self::assertSame('click', $event->type);
        self::assertSame($now, $event->createdAt);
    }
}
