<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Capa;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Capa\CapaStatus;
use Pulsar\Extension\MedicalDevices\Capa\PreventiveAction;

#[CoversClass(PreventiveAction::class)]
final class PreventiveActionTest extends TestCase
{
    #[Test]
    public function minimalConstructorSetsDefaults(): void
    {
        $initiated = new DateTimeImmutable('2026-03-01');
        $action = new PreventiveAction(
            id: 'PA-001',
            title: 'Improve sterilization monitoring',
            potentialNonconformityDescription: 'Risk of undetected sterilization failures',
            initiatedAt: $initiated,
        );

        self::assertSame('PA-001', $action->id);
        self::assertSame(CapaStatus::Initiated, $action->status);
        self::assertNull($action->riskAssessment);
        self::assertNull($action->plannedAction);
        self::assertNull($action->actionDeadline);
        self::assertNull($action->implementationEvidence);
        self::assertNull($action->effectivenessVerification);
        self::assertNull($action->closedAt);
        self::assertSame([], $action->affectedDevices);
        self::assertSame([], $action->triggerSources);
    }

    #[Test]
    public function toArrayWithMinimalRecord(): void
    {
        $action = new PreventiveAction(
            id: 'PA-002',
            title: 'Update labeling process',
            potentialNonconformityDescription: 'Potential mislabeling risk',
            initiatedAt: new DateTimeImmutable('2026-02-15'),
        );

        $array = $action->toArray();

        self::assertSame('PA-002', $array['id']);
        self::assertSame('Update labeling process', $array['title']);
        self::assertSame('Potential mislabeling risk', $array['potential_nonconformity_description']);
        self::assertSame('2026-02-15', $array['initiated_at']);
        self::assertSame('initiated', $array['status']);
        self::assertArrayNotHasKey('risk_assessment', $array);
        self::assertArrayNotHasKey('planned_action', $array);
        self::assertArrayNotHasKey('closed_at', $array);
        self::assertArrayNotHasKey('affected_devices', $array);
        self::assertArrayNotHasKey('trigger_sources', $array);
    }

    #[Test]
    public function toArrayWithFullRecord(): void
    {
        $action = new PreventiveAction(
            id: 'PA-003',
            title: 'Enhance QC process',
            potentialNonconformityDescription: 'QC gap detected',
            initiatedAt: new DateTimeImmutable('2026-01-10'),
            status: CapaStatus::Closed,
            riskAssessment: 'Medium risk identified',
            plannedAction: 'Implement automated QC checks',
            actionDeadline: new DateTimeImmutable('2026-06-30'),
            implementationEvidence: 'Validation report V-2026-001',
            effectivenessVerification: 'No recurrence in 90 days',
            closedAt: new DateTimeImmutable('2026-09-30'),
            affectedDevices: ['DEV-001', 'DEV-002'],
            triggerSources: ['Trend analysis Q4 2025', 'Internal audit'],
        );

        $array = $action->toArray();

        self::assertSame('closed', $array['status']);
        self::assertSame('Medium risk identified', $array['risk_assessment']);
        self::assertSame('Implement automated QC checks', $array['planned_action']);
        self::assertSame('2026-06-30', $array['action_deadline']);
        self::assertSame('Validation report V-2026-001', $array['implementation_evidence']);
        self::assertSame('No recurrence in 90 days', $array['effectiveness_verification']);
        self::assertSame('2026-09-30', $array['closed_at']);
        self::assertSame(['DEV-001', 'DEV-002'], $array['affected_devices']);
        self::assertSame(['Trend analysis Q4 2025', 'Internal audit'], $array['trigger_sources']);
    }
}
