<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log\Compliance;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\Compliance\HipaaLogFormatter;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;

#[CoversClass(HipaaLogFormatter::class)]
final class HipaaLogFormatterTest extends TestCase
{
    private const string TEST_HMAC_KEY = 'test-hmac-key-for-hipaa-log-formatter';

    private HipaaLogFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new HipaaLogFormatter(self::TEST_HMAC_KEY);
    }

    #[Test]
    public function addsPhiAccessFlagWhenPatientIdPresent(): void
    {
        $entry = $this->createEntry(['patient_id' => 'P-12345']);

        $result = $this->formatter->format($entry);

        self::assertTrue($result->context['phi_access']);
    }

    #[Test]
    public function addsPhiAccessFlagWhenDiagnosisPresent(): void
    {
        $entry = $this->createEntry(['diagnosis' => 'condition-A']);

        $result = $this->formatter->format($entry);

        self::assertTrue($result->context['phi_access']);
    }

    #[Test]
    public function addsPhiAccessFlagWhenTreatmentPresent(): void
    {
        $entry = $this->createEntry(['treatment' => 'procedure-B']);

        $result = $this->formatter->format($entry);

        self::assertTrue($result->context['phi_access']);
    }

    #[Test]
    public function addsPhiAccessFlagWhenPrescriptionPresent(): void
    {
        $entry = $this->createEntry(['prescription' => 'medication-C']);

        $result = $this->formatter->format($entry);

        self::assertTrue($result->context['phi_access']);
    }

    #[Test]
    public function doesNotAddPhiFlagWithoutPhiData(): void
    {
        $entry = $this->createEntry(['action' => 'login', 'user_id' => 'admin']);

        $result = $this->formatter->format($entry);

        self::assertArrayNotHasKey('phi_access', $result->context);
    }

    #[Test]
    public function pseudonymizesPatientId(): void
    {
        $entry = $this->createEntry(['patient_id' => 'P-12345']);

        $result = $this->formatter->format($entry);

        $patientId = $result->context['patient_id'];
        self::assertIsString($patientId);
        self::assertStringStartsWith('patient_', $patientId);
        self::assertStringNotContainsString('P-12345', $patientId);
    }

    #[Test]
    public function pseudonymizesPatientName(): void
    {
        $entry = $this->createEntry(['patient_name' => 'John Doe']);

        $result = $this->formatter->format($entry);

        $patientName = $result->context['patient_name'];
        self::assertIsString($patientName);
        self::assertStringStartsWith('patient_', $patientName);
        self::assertStringNotContainsString('John', $patientName);
    }

    #[Test]
    public function pseudonymizesSsn(): void
    {
        $entry = $this->createEntry(['ssn' => '123-45-6789']);

        $result = $this->formatter->format($entry);

        $ssn = $result->context['ssn'];
        self::assertIsString($ssn);
        self::assertStringStartsWith('patient_', $ssn);
        self::assertStringNotContainsString('123', $ssn);
    }

    #[Test]
    public function pseudonymizesMrn(): void
    {
        $entry = $this->createEntry(['mrn' => 'MRN-001']);

        $result = $this->formatter->format($entry);

        $mrn = $result->context['mrn'];
        self::assertIsString($mrn);
        self::assertStringStartsWith('patient_', $mrn);
    }

    #[Test]
    public function pseudonymizesHealthPlanId(): void
    {
        $entry = $this->createEntry(['health_plan_id' => 'HP-999']);

        $result = $this->formatter->format($entry);

        $healthPlanId = $result->context['health_plan_id'];
        self::assertIsString($healthPlanId);
        self::assertStringStartsWith('patient_', $healthPlanId);
    }

    #[Test]
    public function preservesNonPhiContext(): void
    {
        $entry = $this->createEntry([
            'patient_id' => 'P-12345',
            'action' => 'record_view',
            'ward' => 'cardiology',
        ]);

        $result = $this->formatter->format($entry);

        self::assertSame('record_view', $result->context['action']);
        self::assertSame('cardiology', $result->context['ward']);
    }

    #[Test]
    public function consistentPseudonymization(): void
    {
        $entry1 = $this->createEntry(['patient_id' => 'P-12345']);
        $entry2 = $this->createEntry(['patient_id' => 'P-12345']);

        $result1 = $this->formatter->format($entry1);
        $result2 = $this->formatter->format($entry2);

        self::assertSame($result1->context['patient_id'], $result2->context['patient_id']);
    }

    #[Test]
    public function returnsNewLogEntryInstance(): void
    {
        $entry = $this->createEntry(['diagnosis' => 'test']);

        $result = $this->formatter->format($entry);

        self::assertNotSame($entry, $result);
        self::assertSame($entry->level, $result->level);
        self::assertSame($entry->message, $result->message);
        self::assertSame($entry->channel, $result->channel);
    }

    #[Test]
    public function rejectsEmptyHmacKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HipaaLogFormatter('');
    }

    #[Test]
    public function rejectsHmacKeyShorterThanMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 15 bytes — one short of the 16-byte keyed-hash minimum.
        new HipaaLogFormatter('123456789012345');
    }

    #[Test]
    public function handlesMultiplePhiCategories(): void
    {
        $entry = $this->createEntry([
            'patient_id' => 'P-12345',
            'diagnosis' => 'condition-A',
            'treatment' => 'procedure-B',
        ]);

        $result = $this->formatter->format($entry);

        self::assertTrue($result->context['phi_access']);
        $patientId = $result->context['patient_id'];
        self::assertIsString($patientId);
        self::assertStringStartsWith('patient_', $patientId);
        // diagnosis and treatment are PHI flags but not patient ID keys, so they stay
        self::assertSame('condition-A', $result->context['diagnosis']);
        self::assertSame('procedure-B', $result->context['treatment']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function createEntry(array $context = []): LogEntry
    {
        return new LogEntry(
            level: LogLevel::Info,
            message: 'test',
            context: $context,
            channel: 'health',
            timestamp: new DateTimeImmutable('2025-01-01T00:00:00+00:00'),
        );
    }
}
