<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Capa;

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
    public function constructsWithDefaults(): void
    {
        $pa = new PreventiveAction(
            id: 'PA-001',
            title: 'Proactive firmware hardening',
            potentialNonconformityDescription: 'Trend analysis shows increasing thermal events',
            initiatedAt: new DateTimeImmutable('2025-02-01'),
        );

        self::assertSame('PA-001', $pa->id);
        self::assertSame(CapaStatus::Initiated, $pa->status);
        self::assertNull($pa->riskAssessment);
        self::assertSame([], $pa->affectedDevices);
        self::assertSame([], $pa->triggerSources);
    }

    #[Test]
    public function toArrayIncludesRequiredFields(): void
    {
        $pa = new PreventiveAction(
            id: 'PA-001',
            title: 'Preventive measure',
            potentialNonconformityDescription: 'Potential issue detected',
            initiatedAt: new DateTimeImmutable('2025-02-01'),
        );

        $data = $pa->toArray();

        self::assertSame('PA-001', $data['id']);
        self::assertSame('Potential issue detected', $data['potential_nonconformity_description']);
        self::assertSame('initiated', $data['status']);
    }

    #[Test]
    public function toArrayOmitsNullFields(): void
    {
        $pa = new PreventiveAction(
            id: 'PA-001',
            title: 'Test',
            potentialNonconformityDescription: 'Test',
            initiatedAt: new DateTimeImmutable('2025-02-01'),
        );

        $data = $pa->toArray();

        self::assertArrayNotHasKey('risk_assessment', $data);
        self::assertArrayNotHasKey('planned_action', $data);
        self::assertArrayNotHasKey('action_deadline', $data);
        self::assertArrayNotHasKey('closed_at', $data);
        self::assertArrayNotHasKey('trigger_sources', $data);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $pa = new PreventiveAction(
            id: 'PA-002',
            title: 'Battery thermal management improvement',
            potentialNonconformityDescription: 'Thermal trend indicates future risk',
            initiatedAt: new DateTimeImmutable('2025-02-01'),
            status: CapaStatus::Closed,
            riskAssessment: 'Medium risk — could lead to overheating in warm climates',
            plannedAction: 'Add thermal throttling to firmware',
            actionDeadline: new DateTimeImmutable('2025-05-01'),
            implementationEvidence: 'Firmware v3.0 with throttling deployed',
            effectivenessVerification: 'Thermal tests passed for all climate zones',
            closedAt: new DateTimeImmutable('2025-06-15'),
            affectedDevices: ['DI-003'],
            triggerSources: ['Trend analysis Q1 2025', 'Internal audit finding'],
        );

        $data = $pa->toArray();

        self::assertSame('closed', $data['status']);
        self::assertSame('Medium risk — could lead to overheating in warm climates', $data['risk_assessment']);
        self::assertSame('2025-05-01', $data['action_deadline']);
        self::assertSame('2025-06-15', $data['closed_at']);
        self::assertIsArray($data['affected_devices']);
        self::assertCount(1, $data['affected_devices']);
        self::assertIsArray($data['trigger_sources']);
        self::assertCount(2, $data['trigger_sources']);
    }
}
