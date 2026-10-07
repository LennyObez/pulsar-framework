<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Compliance\Verification\ConflictReport;
use Pulsar\Compliance\Verification\RegressionViolation;
use Pulsar\Compliance\Verification\VerificationReport;

#[CoversClass(VerificationReport::class)]
final class VerificationReportTest extends TestCase
{
    public function testCountsAndPassRate(): void
    {
        $report = new VerificationReport(
            frameworks: [ComplianceFramework::Gdpr],
            results: [
                CheckResult::pass('a', 'ok', ComplianceCheckDomain::Encryption),
                CheckResult::pass('b', 'ok', ComplianceCheckDomain::AuditLogging),
                CheckResult::fail('c', 'bad', ComplianceCheckDomain::Authentication),
                CheckResult::skip('d', 'skipped', ComplianceCheckDomain::RateLimiting),
            ],
        );

        self::assertSame(4, $report->totalCount());
        self::assertSame(2, $report->passCount());
        self::assertSame(1, $report->failCount());
        self::assertSame(1, $report->skipCount());
        // pass rate = 2/(2+1) * 100 = 66.67
        self::assertEqualsWithDelta(66.67, $report->passRate(), 0.01);
        self::assertTrue($report->hasFailures());
    }

    public function testPassRateWithNoEvaluatedResults(): void
    {
        $report = new VerificationReport(
            frameworks: [],
            results: [
                CheckResult::skip('a', 'skipped'),
            ],
        );

        self::assertSame(0.0, $report->passRate());
    }

    public function testByStatus(): void
    {
        $pass = CheckResult::pass('a', 'ok');
        $fail = CheckResult::fail('b', 'bad');

        $report = new VerificationReport(
            frameworks: [],
            results: [$pass, $fail],
        );

        self::assertCount(1, $report->byStatus(CheckStatus::Pass));
        self::assertSame('a', $report->byStatus(CheckStatus::Pass)[0]->checkId);
    }

    public function testByDomain(): void
    {
        $enc = CheckResult::pass('a', 'ok', ComplianceCheckDomain::Encryption);
        $auth = CheckResult::pass('b', 'ok', ComplianceCheckDomain::Authentication);

        $report = new VerificationReport(
            frameworks: [],
            results: [$enc, $auth],
        );

        self::assertCount(1, $report->byDomain(ComplianceCheckDomain::Authentication));
        self::assertSame('b', $report->byDomain(ComplianceCheckDomain::Authentication)[0]->checkId);
    }

    public function testHasConflicts(): void
    {
        $reportWithout = new VerificationReport(frameworks: [], results: []);
        self::assertFalse($reportWithout->hasConflicts());

        $conflict = new ConflictReport(
            frameworkA: ComplianceFramework::Gdpr,
            frameworkB: ComplianceFramework::PciDss,
            requirementA: 'Art 17',
            requirementB: 'Req 10.7',
            description: 'test',
            resolution: 'test',
        );

        $reportWith = new VerificationReport(frameworks: [], results: [], conflicts: [$conflict]);
        self::assertTrue($reportWith->hasConflicts());
    }

    public function testHasRegressions(): void
    {
        $reportWithout = new VerificationReport(frameworks: [], results: []);
        self::assertFalse($reportWithout->hasRegressions());

        $violation = new RegressionViolation(
            constraint: 'test',
            expectedDescription: 'a',
            actualDescription: 'b',
            remediation: 'fix',
        );

        $reportWith = new VerificationReport(frameworks: [], results: [], regressions: [$violation]);
        self::assertTrue($reportWith->hasRegressions());
    }

    public function testToArrayStructure(): void
    {
        $report = new VerificationReport(
            frameworks: [ComplianceFramework::Gdpr, ComplianceFramework::PciDss],
            results: [
                CheckResult::pass('a', 'ok', ComplianceCheckDomain::Encryption),
            ],
            generatedAt: 1710410400,
        );

        /** @var array<string, mixed> $array */
        $array = $report->toArray();

        self::assertSame(1710410400, $array['generated_at']);
        self::assertSame(['gdpr', 'pci_dss'], $array['frameworks']);
        /** @var array<string, mixed> $summary */
        $summary = $array['summary'];
        self::assertSame(1, $summary['total']);
        self::assertSame(1, $summary['pass']);
        self::assertSame(0, $summary['fail']);
        self::assertSame(100.0, $summary['pass_rate']);
        /** @var list<array<string, mixed>> $results */
        $results = $array['results'];
        self::assertCount(1, $results);
        self::assertSame('a', $results[0]['check_id']);
        self::assertArrayHasKey('disclaimer', $array);
    }
}
