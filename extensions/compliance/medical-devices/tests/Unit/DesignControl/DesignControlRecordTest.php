<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\DesignControl;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\DesignControl\DesignChange;
use Pulsar\Extension\MedicalDevices\DesignControl\DesignControlRecord;
use Pulsar\Extension\MedicalDevices\DesignControl\DesignPhase;

#[CoversClass(DesignControlRecord::class)]
#[CoversClass(DesignChange::class)]
final class DesignControlRecordTest extends TestCase
{
    #[Test]
    public function isVerificationCompleteRequiresAllThreeFields(): void
    {
        $base = $this->createRecord();
        self::assertFalse($base->isVerificationComplete());

        $withInputs = $this->createRecord(designInputs: ['Input 1']);
        self::assertFalse($withInputs->isVerificationComplete());

        $withOutputs = $this->createRecord(designInputs: ['Input 1'], designOutputs: ['Output 1']);
        self::assertFalse($withOutputs->isVerificationComplete());

        $complete = $this->createRecord(
            designInputs: ['Input 1'],
            designOutputs: ['Output 1'],
            verificationResults: ['Result 1'],
        );
        self::assertTrue($complete->isVerificationComplete());
    }

    #[Test]
    public function isValidationCompleteRequiresResults(): void
    {
        $noValidation = $this->createRecord();
        self::assertFalse($noValidation->isValidationComplete());

        $withValidation = $this->createRecord(validationResults: ['Validation passed']);
        self::assertTrue($withValidation->isValidationComplete());
    }

    #[Test]
    public function toArrayMinimalRecord(): void
    {
        $record = $this->createRecord();
        $array = $record->toArray();

        self::assertSame('DCR-001', $array['id']);
        self::assertSame('DI-001', $array['device_identifier']);
        self::assertSame('Test Device', $array['project_name']);
        self::assertSame('planning', $array['phase']);
        self::assertSame('2026-01-01', $array['created_at']);
        self::assertFalse($array['verification_complete']);
        self::assertFalse($array['validation_complete']);
        self::assertArrayNotHasKey('updated_at', $array);
        self::assertArrayNotHasKey('design_inputs', $array);
        self::assertArrayNotHasKey('design_outputs', $array);
        self::assertArrayNotHasKey('verification_results', $array);
        self::assertArrayNotHasKey('validation_results', $array);
        self::assertArrayNotHasKey('review_notes', $array);
        self::assertArrayNotHasKey('changes', $array);
        self::assertArrayNotHasKey('design_transfer_notes', $array);
    }

    #[Test]
    public function toArrayFullRecord(): void
    {
        $change = new DesignChange(
            changeId: 'CHG-001',
            description: 'Revised sensor calibration',
            justification: 'Improve accuracy',
            requestedAt: new DateTimeImmutable('2026-02-01'),
            approvedAt: new DateTimeImmutable('2026-02-15'),
            approvedBy: 'QA Lead',
            impactAssessment: 'Low risk change',
            requiresRevalidation: true,
        );

        $record = $this->createRecord(
            phase: DesignPhase::Verification,
            updatedAt: new DateTimeImmutable('2026-03-01'),
            designInputs: ['Req-1', 'Req-2'],
            designOutputs: ['Spec-1'],
            verificationResults: ['Test passed'],
            validationResults: ['User acceptance confirmed'],
            reviewNotes: ['Review note 1'],
            changes: [$change],
            designTransferNotes: 'Transfer to manufacturing',
        );

        $array = $record->toArray();

        self::assertSame('verification', $array['phase']);
        self::assertSame('2026-03-01', $array['updated_at']);
        self::assertSame(['Req-1', 'Req-2'], $array['design_inputs']);
        self::assertSame(['Spec-1'], $array['design_outputs']);
        self::assertSame(['Test passed'], $array['verification_results']);
        self::assertSame(['User acceptance confirmed'], $array['validation_results']);
        self::assertSame(['Review note 1'], $array['review_notes']);
        self::assertSame('Transfer to manufacturing', $array['design_transfer_notes']);
        self::assertTrue($array['verification_complete']);
        self::assertTrue($array['validation_complete']);

        // Nested DesignChange
        self::assertCount(1, $array['changes']);
        self::assertSame('CHG-001', $array['changes'][0]['change_id']);
        self::assertSame('2026-02-15', $array['changes'][0]['approved_at']);
        self::assertSame('QA Lead', $array['changes'][0]['approved_by']);
        self::assertTrue($array['changes'][0]['requires_revalidation']);
    }

    #[Test]
    public function designChangeToArrayMinimal(): void
    {
        $change = new DesignChange(
            changeId: 'CHG-002',
            description: 'Label update',
            justification: 'Regulatory requirement',
            requestedAt: new DateTimeImmutable('2026-01-15'),
        );

        $array = $change->toArray();

        self::assertSame('CHG-002', $array['change_id']);
        self::assertSame('Label update', $array['description']);
        self::assertSame('Regulatory requirement', $array['justification']);
        self::assertSame('2026-01-15', $array['requested_at']);
        self::assertFalse($array['requires_revalidation']);
        self::assertArrayNotHasKey('approved_at', $array);
        self::assertArrayNotHasKey('approved_by', $array);
        self::assertArrayNotHasKey('impact_assessment', $array);
    }

    #[Test]
    public function designChangeToArrayWithAllOptionals(): void
    {
        $change = new DesignChange(
            changeId: 'CHG-003',
            description: 'Power supply redesign',
            justification: 'Component obsolescence',
            requestedAt: new DateTimeImmutable('2026-02-01'),
            approvedAt: new DateTimeImmutable('2026-02-10'),
            approvedBy: 'Design Authority',
            impactAssessment: 'Requires full revalidation',
            requiresRevalidation: true,
        );

        $array = $change->toArray();

        self::assertSame('2026-02-10', $array['approved_at']);
        self::assertSame('Design Authority', $array['approved_by']);
        self::assertSame('Requires full revalidation', $array['impact_assessment']);
        self::assertTrue($array['requires_revalidation']);
    }

    /**
     * @param list<string> $designInputs
     * @param list<string> $designOutputs
     * @param list<string> $verificationResults
     * @param list<string> $validationResults
     * @param list<string> $reviewNotes
     * @param list<DesignChange> $changes
     */
    private function createRecord(
        DesignPhase $phase = DesignPhase::Planning,
        ?DateTimeImmutable $updatedAt = null,
        array $designInputs = [],
        array $designOutputs = [],
        array $verificationResults = [],
        array $validationResults = [],
        array $reviewNotes = [],
        array $changes = [],
        ?string $designTransferNotes = null,
    ): DesignControlRecord {
        return new DesignControlRecord(
            id: 'DCR-001',
            deviceIdentifier: 'DI-001',
            projectName: 'Test Device',
            phase: $phase,
            createdAt: new DateTimeImmutable('2026-01-01'),
            updatedAt: $updatedAt,
            designInputs: $designInputs,
            designOutputs: $designOutputs,
            verificationResults: $verificationResults,
            validationResults: $validationResults,
            reviewNotes: $reviewNotes,
            changes: $changes,
            designTransferNotes: $designTransferNotes,
        );
    }
}
