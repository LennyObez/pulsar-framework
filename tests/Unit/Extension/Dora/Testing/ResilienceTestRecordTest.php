<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Dora\Testing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\Testing\ResilienceTestRecord;
use Pulsar\Extension\Dora\Testing\ResilienceTestType;

#[CoversClass(ResilienceTestRecord::class)]
final class ResilienceTestRecordTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $record = new ResilienceTestRecord(
            id: 'RT-001',
            testType: ResilienceTestType::PenetrationTesting,
            executedAt: new DateTimeImmutable('2025-03-01'),
            executedBy: 'External Security Firm',
            result: 'No critical vulnerabilities found',
        );

        self::assertSame([], $record->findings);
        self::assertFalse($record->passed);
        self::assertNull($record->remediationPlan);
    }

    #[Test]
    public function toArrayIncludesRequiredFields(): void
    {
        $record = new ResilienceTestRecord(
            id: 'RT-001',
            testType: ResilienceTestType::VulnerabilityScanning,
            executedAt: new DateTimeImmutable('2025-03-01'),
            executedBy: 'Internal Team',
            result: 'Clean scan',
            passed: true,
        );

        $data = $record->toArray();

        self::assertSame('RT-001', $data['id']);
        self::assertSame('vulnerability_scanning', $data['test_type']);
        self::assertSame('Internal Team', $data['executed_by']);
        self::assertTrue($data['passed']);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $record = new ResilienceTestRecord(
            id: 'RT-002',
            testType: ResilienceTestType::Tlpt,
            executedAt: new DateTimeImmutable('2025-02-15'),
            executedBy: 'Qualified TLPT Provider',
            result: 'Partial compromise achieved',
            targetSystems: ['Core Banking', 'Payment Gateway'],
            findings: ['Lateral movement possible', 'Weak segmentation'],
            remediationPlan: 'Implement network microsegmentation',
            remediationDeadline: new DateTimeImmutable('2025-06-01'),
            recoveryTimeActual: '2 hours 30 minutes',
            passed: false,
        );

        /** @var array<string, mixed> $data */
        $data = $record->toArray();

        self::assertSame('tlpt', $data['test_type']);
        /** @var list<string> $targets */
        $targets = $data['target_systems'];
        self::assertCount(2, $targets);
        /** @var list<string> $findings */
        $findings = $data['findings'];
        self::assertCount(2, $findings);
        self::assertSame('2025-06-01', $data['remediation_deadline']);
        self::assertSame('2 hours 30 minutes', $data['recovery_time_actual']);
        self::assertFalse($data['passed']);
    }

    #[Test]
    public function testTypeEnumHasAllTypes(): void
    {
        self::assertCount(11, ResilienceTestType::cases());
        self::assertSame('tlpt', ResilienceTestType::Tlpt->value);
        self::assertSame('penetration_testing', ResilienceTestType::PenetrationTesting->value);
    }
}
