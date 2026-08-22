<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\CloudSwitching;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\CloudSwitching\SwitchingPlan;
use Pulsar\Extension\DataAct\CloudSwitching\SwitchingStatus;
use Pulsar\Extension\DataAct\Portability\ExportRequest;
use Pulsar\Extension\DataAct\Portability\ExportStatus;

#[CoversClass(SwitchingPlan::class)]
final class SwitchingPlanTest extends TestCase
{
    #[Test]
    public function constructionProperties(): void
    {
        $plan = $this->createPlan(SwitchingStatus::Initiated);

        self::assertSame('cust-1', $plan->customerId);
        self::assertSame('aws', $plan->targetProvider);
        self::assertSame(SwitchingStatus::Initiated, $plan->status);
    }

    #[Test]
    public function isOverdueReturnsTrueWhenPastDeadlineAndNotCompleted(): void
    {
        $plan = $this->createPlan(
            SwitchingStatus::Initiated,
            new DateTimeImmutable('-1 day'),
        );

        self::assertTrue($plan->isOverdue());
    }

    #[Test]
    public function isOverdueReturnsFalseWhenCompleted(): void
    {
        $plan = $this->createPlan(
            SwitchingStatus::Completed,
            new DateTimeImmutable('-1 day'),
        );

        self::assertFalse($plan->isOverdue());
    }

    #[Test]
    public function isOverdueReturnsFalseWhenDeadlineInFuture(): void
    {
        $plan = $this->createPlan(SwitchingStatus::Initiated);

        self::assertFalse($plan->isOverdue());
    }

    private function createPlan(
        SwitchingStatus $status,
        ?DateTimeImmutable $deadline = null,
    ): SwitchingPlan {
        return new SwitchingPlan(
            customerId: 'cust-1',
            targetProvider: 'aws',
            exportRequest: new ExportRequest(
                id: 'exp-1',
                userId: 'cust-1',
                format: 'json',
                scopes: [],
                status: ExportStatus::Pending,
                requestedAt: new DateTimeImmutable(),
                deadline: new DateTimeImmutable('+30 days'),
            ),
            initiatedAt: new DateTimeImmutable(),
            transitionDeadline: $deadline ?? new DateTimeImmutable('+30 days'),
            status: $status,
        );
    }
}
