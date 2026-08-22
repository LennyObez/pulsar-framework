<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\DesignControl;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\DesignControl\DesignChange;
use Pulsar\Extension\MedicalDevices\DesignControl\DesignControlRecord;
use Pulsar\Extension\MedicalDevices\DesignControl\DesignPhase;

#[CoversClass(DesignControlRecord::class)]
final class DesignControlRecordTest extends TestCase
{
    #[Test]
    public function verificationRequiresInputsOutputsAndResults(): void
    {
        $incomplete = new DesignControlRecord(
            id: 'DC-001',
            deviceIdentifier: 'DI-001',
            projectName: 'Project Alpha',
            phase: DesignPhase::Design,
            createdAt: new DateTimeImmutable('2025-01-01'),
            designInputs: ['Requirement A'],
            designOutputs: ['Output A'],
        );

        self::assertFalse($incomplete->isVerificationComplete());

        $complete = new DesignControlRecord(
            id: 'DC-002',
            deviceIdentifier: 'DI-001',
            projectName: 'Project Alpha',
            phase: DesignPhase::Verification,
            createdAt: new DateTimeImmutable('2025-01-01'),
            designInputs: ['Requirement A'],
            designOutputs: ['Output A'],
            verificationResults: ['Output A meets Requirement A'],
        );

        self::assertTrue($complete->isVerificationComplete());
    }

    #[Test]
    public function verificationIncompleteWithEmptyInputs(): void
    {
        $record = new DesignControlRecord(
            id: 'DC-001',
            deviceIdentifier: 'DI-001',
            projectName: 'Project',
            phase: DesignPhase::Verification,
            createdAt: new DateTimeImmutable('2025-01-01'),
            designOutputs: ['Output A'],
            verificationResults: ['Result A'],
        );

        self::assertFalse($record->isVerificationComplete());
    }

    #[Test]
    public function validationRequiresResults(): void
    {
        $incomplete = new DesignControlRecord(
            id: 'DC-001',
            deviceIdentifier: 'DI-001',
            projectName: 'Project',
            phase: DesignPhase::Validation,
            createdAt: new DateTimeImmutable('2025-01-01'),
        );

        self::assertFalse($incomplete->isValidationComplete());

        $complete = new DesignControlRecord(
            id: 'DC-002',
            deviceIdentifier: 'DI-001',
            projectName: 'Project',
            phase: DesignPhase::Validation,
            createdAt: new DateTimeImmutable('2025-01-01'),
            validationResults: ['Device meets user needs'],
        );

        self::assertTrue($complete->isValidationComplete());
    }

    #[Test]
    public function toArrayIncludesComputedFields(): void
    {
        $record = new DesignControlRecord(
            id: 'DC-001',
            deviceIdentifier: 'DI-001',
            projectName: 'Project Alpha',
            phase: DesignPhase::Complete,
            createdAt: new DateTimeImmutable('2025-01-01'),
            designInputs: ['Req A'],
            designOutputs: ['Out A'],
            verificationResults: ['Verified'],
            validationResults: ['Validated'],
        );

        $data = $record->toArray();

        self::assertTrue($data['verification_complete']);
        self::assertTrue($data['validation_complete']);
        self::assertSame('complete', $data['phase']);
        self::assertSame('2025-01-01', $data['created_at']);
    }

    #[Test]
    public function toArrayIncludesDesignChanges(): void
    {
        $change = new DesignChange(
            changeId: 'DCH-001',
            description: 'Updated sensor interface',
            justification: 'Improved accuracy requirement',
            requestedAt: new DateTimeImmutable('2025-03-01'),
            requiresRevalidation: true,
        );

        $record = new DesignControlRecord(
            id: 'DC-001',
            deviceIdentifier: 'DI-001',
            projectName: 'Project',
            phase: DesignPhase::Review,
            createdAt: new DateTimeImmutable('2025-01-01'),
            changes: [$change],
        );

        $data = $record->toArray();

        self::assertIsArray($data['changes']);
        /** @var list<array<string, mixed>> $changes */
        $changes = $data['changes'];
        self::assertCount(1, $changes);
        self::assertSame('DCH-001', $changes[0]['change_id']);
        self::assertTrue($changes[0]['requires_revalidation']);
    }

    #[Test]
    public function toArrayOmitsEmptyArraysAndNulls(): void
    {
        $record = new DesignControlRecord(
            id: 'DC-001',
            deviceIdentifier: 'DI-001',
            projectName: 'Project',
            phase: DesignPhase::Planning,
            createdAt: new DateTimeImmutable('2025-01-01'),
        );

        $data = $record->toArray();

        self::assertArrayNotHasKey('design_inputs', $data);
        self::assertArrayNotHasKey('design_outputs', $data);
        self::assertArrayNotHasKey('verification_results', $data);
        self::assertArrayNotHasKey('changes', $data);
        self::assertArrayNotHasKey('updated_at', $data);
        self::assertArrayNotHasKey('design_transfer_notes', $data);
    }

    #[Test]
    public function allDesignPhasesHaveValues(): void
    {
        self::assertCount(9, DesignPhase::cases());
        self::assertSame('planning', DesignPhase::Planning->value);
        self::assertSame('complete', DesignPhase::Complete->value);
    }
}
