<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use {{namespace}}\Entity\Patient;
use {{namespace}}\Entity\PatientStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Patient::class)]
final class PatientTest extends TestCase
{
    #[Test]
    public function it_creates_an_active_patient(): void
    {
        $patient = new Patient(
            id: 'pat_001',
            medicalRecordNumber: 'MRN-12345',
            firstName: 'Jane',
            lastName: 'Doe',
            dateOfBirth: new \DateTimeImmutable('1990-01-15'),
        );

        self::assertSame('pat_001', $patient->id);
        self::assertSame('Jane Doe', $patient->fullName());
        self::assertTrue($patient->isActive());
    }

    #[Test]
    public function it_calculates_age_in_years(): void
    {
        $patient = new Patient(
            id: 'pat_002',
            medicalRecordNumber: 'MRN-67890',
            firstName: 'John',
            lastName: 'Smith',
            dateOfBirth: new \DateTimeImmutable('2000-06-15'),
        );

        self::assertGreaterThan(0, $patient->ageInYears());
    }

    // TODO: Add tests for PHI access logging integration and status transitions
}
