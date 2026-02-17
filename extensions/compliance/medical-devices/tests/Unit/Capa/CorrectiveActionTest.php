<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Capa;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Capa\CapaStatus;
use Pulsar\Extension\MedicalDevices\Capa\CorrectiveAction;
use Pulsar\Extension\MedicalDevices\Capa\PreventiveAction;

#[CoversClass(CorrectiveAction::class)]
#[CoversClass(PreventiveAction::class)]
final class CorrectiveActionTest extends TestCase
{
    #[Test]
    public function correctiveActionToArrayMinimal(): void
    {
        $ca = new CorrectiveAction(
            id: 'CA-001',
            title: 'Sensor calibration failure',
            nonconformityDescription: 'Sensor readings out of spec',
            initiatedAt: new DateTimeImmutable('2026-01-15'),
        );

        $array = $ca->toArray();

        self::assertSame('CA-001', $array['id']);
        self::assertSame('Sensor calibration failure', $array['title']);
        self::assertSame('Sensor readings out of spec', $array['nonconformity_description']);
        self::assertSame('2026-01-15', $array['initiated_at']);
        self::assertSame('initiated', $array['status']);
        self::assertArrayNotHasKey('root_cause_analysis', $array);
        self::assertArrayNotHasKey('planned_action', $array);
        self::assertArrayNotHasKey('action_deadline', $array);
        self::assertArrayNotHasKey('implementation_evidence', $array);
        self::assertArrayNotHasKey('effectiveness_verification', $array);
        self::assertArrayNotHasKey('closed_at', $array);
        self::assertArrayNotHasKey('affected_devices', $array);
        self::assertArrayNotHasKey('related_complaints', $array);
    }

    #[Test]
    public function correctiveActionToArrayFull(): void
    {
        $ca = new CorrectiveAction(
            id: 'CA-002',
            title: 'Display malfunction',
            nonconformityDescription: 'Display shows incorrect values',
            initiatedAt: new DateTimeImmutable('2026-02-01'),
            status: CapaStatus::Closed,
            rootCauseAnalysis: 'Firmware bug in rendering pipeline',
            plannedAction: 'Patch firmware version 2.1.3',
            actionDeadline: new DateTimeImmutable('2026-03-01'),
            implementationEvidence: 'Firmware deployed to all units',
            effectivenessVerification: 'Post-update testing passed',
            closedAt: new DateTimeImmutable('2026-03-15'),
            affectedDevices: ['DI-100', 'DI-101'],
            relatedComplaints: ['COMP-001', 'COMP-002'],
        );

        $array = $ca->toArray();

        self::assertSame('closed', $array['status']);
        self::assertSame('Firmware bug in rendering pipeline', $array['root_cause_analysis']);
        self::assertSame('Patch firmware version 2.1.3', $array['planned_action']);
        self::assertSame('2026-03-01', $array['action_deadline']);
        self::assertSame('Firmware deployed to all units', $array['implementation_evidence']);
        self::assertSame('Post-update testing passed', $array['effectiveness_verification']);
        self::assertSame('2026-03-15', $array['closed_at']);
        self::assertSame(['DI-100', 'DI-101'], $array['affected_devices']);
        self::assertSame(['COMP-001', 'COMP-002'], $array['related_complaints']);
    }

    #[Test]
    public function preventiveActionToArrayMinimal(): void
    {
        $pa = new PreventiveAction(
            id: 'PA-001',
            title: 'Battery degradation prevention',
            potentialNonconformityDescription: 'Battery may degrade below threshold',
            initiatedAt: new DateTimeImmutable('2026-01-20'),
        );

        $array = $pa->toArray();

        self::assertSame('PA-001', $array['id']);
        self::assertSame('Battery degradation prevention', $array['title']);
        self::assertSame('Battery may degrade below threshold', $array['potential_nonconformity_description']);
        self::assertSame('2026-01-20', $array['initiated_at']);
        self::assertSame('initiated', $array['status']);
        self::assertArrayNotHasKey('risk_assessment', $array);
        self::assertArrayNotHasKey('planned_action', $array);
        self::assertArrayNotHasKey('action_deadline', $array);
        self::assertArrayNotHasKey('trigger_sources', $array);
    }

    #[Test]
    public function preventiveActionToArrayFull(): void
    {
        $pa = new PreventiveAction(
            id: 'PA-002',
            title: 'Connector wear prevention',
            potentialNonconformityDescription: 'Connector pins may wear after 10k cycles',
            initiatedAt: new DateTimeImmutable('2026-02-10'),
            status: CapaStatus::EffectivenessVerified,
            riskAssessment: 'Medium risk: potential patient harm',
            plannedAction: 'Switch to gold-plated connectors',
            actionDeadline: new DateTimeImmutable('2026-04-01'),
            implementationEvidence: 'New connectors installed in production',
            effectivenessVerification: 'Endurance test passed 50k cycles',
            closedAt: new DateTimeImmutable('2026-04-15'),
            affectedDevices: ['DI-200'],
            triggerSources: ['trend_analysis', 'field_report'],
        );

        $array = $pa->toArray();

        self::assertSame('effectiveness_verified', $array['status']);
        self::assertSame('Medium risk: potential patient harm', $array['risk_assessment']);
        self::assertSame('Switch to gold-plated connectors', $array['planned_action']);
        self::assertSame('2026-04-01', $array['action_deadline']);
        self::assertSame('Endurance test passed 50k cycles', $array['effectiveness_verification']);
        self::assertSame('2026-04-15', $array['closed_at']);
        self::assertSame(['DI-200'], $array['affected_devices']);
        self::assertSame(['trend_analysis', 'field_report'], $array['trigger_sources']);
    }

    #[Test]
    public function allCapaStatusCasesExist(): void
    {
        $cases = CapaStatus::cases();

        self::assertCount(6, $cases);
        self::assertSame('initiated', CapaStatus::Initiated->value);
        self::assertSame('investigating', CapaStatus::Investigating->value);
        self::assertSame('action_planned', CapaStatus::ActionPlanned->value);
        self::assertSame('action_implemented', CapaStatus::ActionImplemented->value);
        self::assertSame('effectiveness_verified', CapaStatus::EffectivenessVerified->value);
        self::assertSame('closed', CapaStatus::Closed->value);
    }
}
