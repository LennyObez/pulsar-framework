<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\FunnelDefinition;
use Pulsar\Extension\Analytics\Domain\FunnelStep;
use Pulsar\Extension\Analytics\Domain\FunnelStepType;

#[CoversClass(FunnelDefinition::class)]
final class FunnelDefinitionTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $now = new DateTimeImmutable('2026-03-01');
        $steps = [
            new FunnelStep(1, 'Landing', FunnelStepType::PageVisit, '/'),
            new FunnelStep(2, 'Signup', FunnelStepType::CustomEvent, 'signup'),
        ];

        $funnel = new FunnelDefinition(
            id: 'f-1',
            siteId: 'site-1',
            name: 'Signup Funnel',
            steps: $steps,
            createdAt: $now,
        );

        self::assertSame('f-1', $funnel->id);
        self::assertSame('site-1', $funnel->siteId);
        self::assertSame('Signup Funnel', $funnel->name);
        self::assertCount(2, $funnel->steps);
        self::assertSame($now, $funnel->createdAt);
    }

    #[Test]
    public function createdAtDefaultsToNow(): void
    {
        $funnel = new FunnelDefinition(
            id: 'f-2',
            siteId: 'site-1',
            name: 'Test',
            steps: [],
        );

        self::assertInstanceOf(DateTimeImmutable::class, $funnel->createdAt);
    }

    #[Test]
    public function stepsArePreservedInOrder(): void
    {
        $steps = [
            new FunnelStep(1, 'Home', FunnelStepType::PageVisit, '/'),
            new FunnelStep(2, 'Product', FunnelStepType::PageVisit, '/product/*'),
            new FunnelStep(3, 'Cart', FunnelStepType::PageVisit, '/cart'),
            new FunnelStep(4, 'Purchase', FunnelStepType::CustomEvent, 'purchase'),
        ];

        $funnel = new FunnelDefinition('f-3', 'site-1', 'Purchase Funnel', $steps);

        self::assertSame(1, $funnel->steps[0]->position);
        self::assertSame(4, $funnel->steps[3]->position);
        self::assertSame('Purchase', $funnel->steps[3]->name);
    }
}
