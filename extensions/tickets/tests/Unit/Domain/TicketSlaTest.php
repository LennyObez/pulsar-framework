<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\EscalationRule;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketSla;

final class TicketSlaTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesCorrectSla(): void
    {
        $sla = TicketSla::fromArray([
            'priority' => 'critical',
            'first_response_minutes' => 15,
            'resolution_minutes' => 60,
            'escalation_rules' => [
                [
                    'threshold_minutes' => 30,
                    'action' => 'notify_manager',
                    'target' => 'manager@example.com',
                ],
            ],
        ]);

        self::assertSame(TicketPriority::Critical, $sla->priority);
        self::assertSame(15, $sla->firstResponseMinutes);
        self::assertSame(60, $sla->resolutionMinutes);
        self::assertCount(1, $sla->escalationRules);
        self::assertSame(30, $sla->escalationRules[0]->thresholdMinutes);
        self::assertSame('notify_manager', $sla->escalationRules[0]->action);
        self::assertSame('manager@example.com', $sla->escalationRules[0]->target);
    }

    #[Test]
    public function fromArrayWithNoEscalationRules(): void
    {
        $sla = TicketSla::fromArray([
            'priority' => 'low',
            'first_response_minutes' => 480,
            'resolution_minutes' => 2880,
        ]);

        self::assertSame(TicketPriority::Low, $sla->priority);
        self::assertSame([], $sla->escalationRules);
    }

    #[Test]
    public function escalationRuleWithNullTarget(): void
    {
        $rule = new EscalationRule(
            thresholdMinutes: 60,
            action: 'change_priority',
            target: null,
        );

        self::assertSame(60, $rule->thresholdMinutes);
        self::assertSame('change_priority', $rule->action);
        self::assertNull($rule->target);
    }

    #[Test]
    public function fromArrayWithMultipleEscalationRules(): void
    {
        $sla = TicketSla::fromArray([
            'priority' => 'urgent',
            'first_response_minutes' => 30,
            'resolution_minutes' => 120,
            'escalation_rules' => [
                ['threshold_minutes' => 30, 'action' => 'notify_manager'],
                ['threshold_minutes' => 60, 'action' => 'reassign', 'target' => 'senior-agent'],
                ['threshold_minutes' => 90, 'action' => 'change_priority'],
            ],
        ]);

        self::assertCount(3, $sla->escalationRules);
        self::assertSame('reassign', $sla->escalationRules[1]->action);
        self::assertSame('senior-agent', $sla->escalationRules[1]->target);
        self::assertNull($sla->escalationRules[0]->target);
    }
}
