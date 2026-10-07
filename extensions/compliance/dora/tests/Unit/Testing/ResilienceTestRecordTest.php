<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Tests\Unit\Testing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\Testing\ResilienceTestRecord;
use Pulsar\Extension\Dora\Testing\ResilienceTestType;

#[CoversClass(ResilienceTestRecord::class)]
final class ResilienceTestRecordTest extends TestCase
{
    #[Test]
    public function minimalConstructorSetsDefaults(): void
    {
        $executed = new DateTimeImmutable('2026-03-01');
        $record = new ResilienceTestRecord(
            id: 'TEST-001',
            testType: ResilienceTestType::PenetrationTesting,
            executedAt: $executed,
            executedBy: 'External Auditor',
            result: 'No critical findings',
        );

        self::assertSame('TEST-001', $record->id);
        self::assertSame(ResilienceTestType::PenetrationTesting, $record->testType);
        self::assertSame('External Auditor', $record->executedBy);
        self::assertSame([], $record->targetSystems);
        self::assertSame([], $record->findings);
        self::assertNull($record->remediationPlan);
        self::assertNull($record->remediationDeadline);
        self::assertNull($record->recoveryTimeActual);
        self::assertFalse($record->passed);
    }

    #[Test]
    public function toArrayWithMinimalRecord(): void
    {
        $executed = new DateTimeImmutable('2026-03-01');
        $record = new ResilienceTestRecord(
            id: 'TEST-002',
            testType: ResilienceTestType::VulnerabilityScanning,
            executedAt: $executed,
            executedBy: 'SecOps',
            result: 'Clean scan',
            passed: true,
        );

        $array = $record->toArray();

        self::assertSame('TEST-002', $array['id']);
        self::assertSame('vulnerability_scanning', $array['test_type']);
        self::assertSame('2026-03-01', $array['executed_at']);
        self::assertSame('SecOps', $array['executed_by']);
        self::assertSame('Clean scan', $array['result']);
        self::assertTrue($array['passed']);
        self::assertArrayNotHasKey('target_systems', $array);
        self::assertArrayNotHasKey('findings', $array);
        self::assertArrayNotHasKey('remediation_plan', $array);
    }

    #[Test]
    public function toArrayWithFullRecord(): void
    {
        $executed = new DateTimeImmutable('2026-03-01');
        $deadline = new DateTimeImmutable('2026-04-01');

        $record = new ResilienceTestRecord(
            id: 'TEST-003',
            testType: ResilienceTestType::Tlpt,
            executedAt: $executed,
            executedBy: 'Red Team',
            result: 'Multiple findings',
            targetSystems: ['payments', 'auth'],
            findings: ['SQL injection in legacy endpoint', 'Weak session timeout'],
            remediationPlan: 'Patch all findings by deadline',
            remediationDeadline: $deadline,
            recoveryTimeActual: '2 hours',
            passed: false,
        );

        $array = $record->toArray();

        self::assertSame(['payments', 'auth'], $array['target_systems']);
        self::assertCount(2, $array['findings']);
        self::assertSame('Patch all findings by deadline', $array['remediation_plan']);
        self::assertSame('2026-04-01', $array['remediation_deadline']);
        self::assertSame('2 hours', $array['recovery_time_actual']);
        self::assertFalse($array['passed']);
    }

    /**
     * @return iterable<string, array{ResilienceTestType, string}>
     */
    public static function resilienceTestTypeProvider(): iterable
    {
        yield 'vulnerability_scanning' => [ResilienceTestType::VulnerabilityScanning, 'vulnerability_scanning'];
        yield 'open_source_analysis' => [ResilienceTestType::OpenSourceAnalysis, 'open_source_analysis'];
        yield 'network_security' => [ResilienceTestType::NetworkSecurity, 'network_security'];
        yield 'penetration_testing' => [ResilienceTestType::PenetrationTesting, 'penetration_testing'];
        yield 'gap_analysis' => [ResilienceTestType::GapAnalysis, 'gap_analysis'];
        yield 'physical_security' => [ResilienceTestType::PhysicalSecurity, 'physical_security'];
        yield 'source_code_review' => [ResilienceTestType::SourceCodeReview, 'source_code_review'];
        yield 'scenario_based_testing' => [ResilienceTestType::ScenarioBasedTesting, 'scenario_based_testing'];
        yield 'performance_testing' => [ResilienceTestType::PerformanceTesting, 'performance_testing'];
        yield 'end_to_end_testing' => [ResilienceTestType::EndToEndTesting, 'end_to_end_testing'];
        yield 'tlpt' => [ResilienceTestType::Tlpt, 'tlpt'];
    }

    #[Test]
    #[DataProvider('resilienceTestTypeProvider')]
    public function testTypeEnumHasExpectedValue(ResilienceTestType $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }
}
