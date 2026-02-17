<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\EscalationRule;

#[CoversClass(EscalationRule::class)]
final class EscalationRuleTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $rule = new EscalationRule(
            thresholdMinutes: 60,
            action: 'notify_manager',
            target: 'manager@example.com',
        );

        self::assertSame(60, $rule->thresholdMinutes);
        self::assertSame('notify_manager', $rule->action);
        self::assertSame('manager@example.com', $rule->target);
    }

    #[Test]
    public function constructorAcceptsNullTarget(): void
    {
        $rule = new EscalationRule(
            thresholdMinutes: 120,
            action: 'change_priority',
            target: null,
        );

        self::assertSame(120, $rule->thresholdMinutes);
        self::assertSame('change_priority', $rule->action);
        self::assertNull($rule->target);
    }

    #[Test]
    public function constructorAcceptsZeroThreshold(): void
    {
        $rule = new EscalationRule(
            thresholdMinutes: 0,
            action: 'immediate_alert',
            target: 'ops@example.com',
        );

        self::assertSame(0, $rule->thresholdMinutes);
    }

    #[Test]
    public function constructorAcceptsReassignAction(): void
    {
        $rule = new EscalationRule(
            thresholdMinutes: 30,
            action: 'reassign',
            target: 'senior-agent-id',
        );

        self::assertSame('reassign', $rule->action);
        self::assertSame('senior-agent-id', $rule->target);
    }
}
