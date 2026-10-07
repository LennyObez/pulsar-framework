<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Capa;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Capa\CapaStatus;
use Pulsar\Extension\MedicalDevices\Capa\CorrectiveAction;

#[CoversClass(CorrectiveAction::class)]
final class CorrectiveActionTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $capa = new CorrectiveAction(
            id: 'CA-001',
            title: 'Sensor calibration drift',
            nonconformityDescription: 'Sensor readings drifting outside specification after 6 months',
            initiatedAt: new DateTimeImmutable('2025-03-01'),
        );

        self::assertSame('CA-001', $capa->id);
        self::assertSame(CapaStatus::Initiated, $capa->status);
        self::assertNull($capa->rootCauseAnalysis);
        self::assertNull($capa->closedAt);
        self::assertSame([], $capa->affectedDevices);
        self::assertSame([], $capa->relatedComplaints);
    }

    #[Test]
    public function toArrayIncludesRequiredFields(): void
    {
        $capa = new CorrectiveAction(
            id: 'CA-001',
            title: 'Sensor drift',
            nonconformityDescription: 'Readings out of spec',
            initiatedAt: new DateTimeImmutable('2025-03-01'),
        );

        $data = $capa->toArray();

        self::assertSame('CA-001', $data['id']);
        self::assertSame('Sensor drift', $data['title']);
        self::assertSame('Readings out of spec', $data['nonconformity_description']);
        self::assertSame('2025-03-01', $data['initiated_at']);
        self::assertSame('initiated', $data['status']);
    }

    #[Test]
    public function toArrayOmitsNullFields(): void
    {
        $capa = new CorrectiveAction(
            id: 'CA-001',
            title: 'Test',
            nonconformityDescription: 'Test',
            initiatedAt: new DateTimeImmutable('2025-03-01'),
        );

        $data = $capa->toArray();

        self::assertArrayNotHasKey('root_cause_analysis', $data);
        self::assertArrayNotHasKey('planned_action', $data);
        self::assertArrayNotHasKey('action_deadline', $data);
        self::assertArrayNotHasKey('implementation_evidence', $data);
        self::assertArrayNotHasKey('closed_at', $data);
        self::assertArrayNotHasKey('affected_devices', $data);
    }

    #[Test]
    public function toArrayIncludesFullLifecycleFields(): void
    {
        $capa = new CorrectiveAction(
            id: 'CA-002',
            title: 'Battery overheating',
            nonconformityDescription: 'Battery exceeds thermal limits under sustained use',
            initiatedAt: new DateTimeImmutable('2025-01-15'),
            status: CapaStatus::EffectivenessVerified,
            rootCauseAnalysis: 'Inadequate thermal management in firmware v2.1',
            plannedAction: 'Firmware update to improve power management',
            actionDeadline: new DateTimeImmutable('2025-04-01'),
            implementationEvidence: 'Firmware v2.2 deployed to all units',
            effectivenessVerification: 'No overheating events in 3 months post-update',
            closedAt: new DateTimeImmutable('2025-07-01'),
            affectedDevices: ['DI-001', 'DI-002'],
            relatedComplaints: ['COMP-100', 'COMP-101', 'COMP-102'],
        );

        $data = $capa->toArray();

        self::assertSame('effectiveness_verified', $data['status']);
        self::assertSame('Inadequate thermal management in firmware v2.1', $data['root_cause_analysis']);
        self::assertSame('2025-04-01', $data['action_deadline']);
        self::assertSame('2025-07-01', $data['closed_at']);
        self::assertIsArray($data['affected_devices']);
        self::assertCount(2, $data['affected_devices']);
        self::assertIsArray($data['related_complaints']);
        self::assertCount(3, $data['related_complaints']);
    }

    #[Test]
    public function capaStatusEnumHasAllCases(): void
    {
        self::assertCount(6, CapaStatus::cases());
        self::assertSame('initiated', CapaStatus::Initiated->value);
        self::assertSame('closed', CapaStatus::Closed->value);
    }
}
