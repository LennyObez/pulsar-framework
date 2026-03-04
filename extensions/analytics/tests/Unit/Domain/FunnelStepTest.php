<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\FunnelStep;
use Pulsar\Extension\Analytics\Domain\FunnelStepType;

#[CoversClass(FunnelStep::class)]
final class FunnelStepTest extends TestCase
{
    #[Test]
    public function constructWithPageVisitType(): void
    {
        $step = new FunnelStep(
            position: 1,
            name: 'Landing Page',
            type: FunnelStepType::PageVisit,
            value: '/landing',
        );

        self::assertSame(1, $step->position);
        self::assertSame('Landing Page', $step->name);
        self::assertSame(FunnelStepType::PageVisit, $step->type);
        self::assertSame('/landing', $step->value);
    }

    #[Test]
    public function constructWithCustomEventType(): void
    {
        $step = new FunnelStep(
            position: 3,
            name: 'Purchase Complete',
            type: FunnelStepType::CustomEvent,
            value: 'purchase',
        );

        self::assertSame(FunnelStepType::CustomEvent, $step->type);
        self::assertSame('purchase', $step->value);
    }
}
