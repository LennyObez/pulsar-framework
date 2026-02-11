<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use {{namespace}}\Entity\MedicalRecord;
use {{namespace}}\Entity\RecordConfidentiality;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MedicalRecord::class)]
final class MedicalRecordTest extends TestCase
{
    #[Test]
    public function it_creates_a_normal_record(): void
    {
        $record = new MedicalRecord(
            id: 'rec_001',
            patientId: 'pat_001',
            providerId: 'prv_001',
            recordType: 'encounter',
            content: 'Patient presents with...',
        );

        self::assertSame('rec_001', $record->id);
        self::assertFalse($record->isRestricted());
        self::assertFalse($record->requiresSpecialConsent());
    }

    #[Test]
    public function it_identifies_restricted_records(): void
    {
        $record = new MedicalRecord(
            id: 'rec_002',
            patientId: 'pat_001',
            providerId: 'prv_001',
            recordType: 'psychotherapy',
            content: 'Session notes...',
            confidentiality: RecordConfidentiality::Restricted,
        );

        self::assertTrue($record->isRestricted());
        self::assertTrue($record->requiresSpecialConsent());
    }

    // TODO: Add tests for access control and audit trail integration
}
