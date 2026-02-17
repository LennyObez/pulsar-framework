<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Compliance\Verification\ComplianceDiagnostic;
use Pulsar\Compliance\Verification\ConflictReport;
use Pulsar\Compliance\Verification\RegressionViolation;
use Pulsar\Compliance\Verification\VerificationReport;

#[CoversClass(ComplianceDiagnostic::class)]
final class ComplianceDiagnosticTest extends TestCase
{
    public function testTextFormatWithPassingReport(): void
    {
        $report = new VerificationReport(
            frameworks: [ComplianceFramework::Gdpr],
            results: [
                CheckResult::pass('a', 'Encryption ok', ComplianceCheckDomain::Encryption),
            ],
        );

        $diagnostic = new ComplianceDiagnostic();
        $output = $diagnostic->format($report, 'text');

        self::assertSame(0, $output['exitCode']);
        self::assertStringContainsString('PASS', $output['output']);
        self::assertStringContainsString('Encryption ok', $output['output']);
        self::assertStringContainsString('100.0%', $output['output']);
    }

    public function testTextFormatWithFailingReport(): void
    {
        $report = new VerificationReport(
            frameworks: [],
            results: [
                CheckResult::fail('b', 'TLS missing', ComplianceCheckDomain::TransportSecurity, ['Enable TLS']),
            ],
        );

        $diagnostic = new ComplianceDiagnostic();
        $output = $diagnostic->format($report, 'text');

        self::assertSame(1, $output['exitCode']);
        self::assertStringContainsString('FAIL', $output['output']);
        self::assertStringContainsString('FIX:', $output['output']);
        self::assertStringContainsString('Enable TLS', $output['output']);
    }

    public function testJsonFormat(): void
    {
        $report = new VerificationReport(
            frameworks: [ComplianceFramework::PciDss],
            results: [
                CheckResult::pass('enc', 'ok'),
            ],
            generatedAt: 1710410400,
        );

        $diagnostic = new ComplianceDiagnostic();
        $output = $diagnostic->format($report, 'json');

        self::assertSame(0, $output['exitCode']);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($output['output'], true);
        self::assertSame(1710410400, $decoded['generated_at']);
        self::assertSame(['pci_dss'], $decoded['frameworks']);
    }

    public function testTextFormatWithConflicts(): void
    {
        $conflict = new ConflictReport(
            frameworkA: ComplianceFramework::Gdpr,
            frameworkB: ComplianceFramework::PciDss,
            requirementA: 'Art. 17',
            requirementB: 'Req. 10.7',
            description: 'Erasure vs retention',
            resolution: 'Pseudonymize',
        );

        $report = new VerificationReport(
            frameworks: [],
            results: [CheckResult::pass('a', 'ok')],
            conflicts: [$conflict],
        );

        $diagnostic = new ComplianceDiagnostic();
        $output = $diagnostic->format($report, 'text');

        self::assertStringContainsString('Cross-Framework Conflicts', $output['output']);
        self::assertStringContainsString('GDPR', $output['output']);
        self::assertStringContainsString('PCI_DSS', $output['output']);
        self::assertStringContainsString('Pseudonymize', $output['output']);
    }

    public function testTextFormatWithRegressions(): void
    {
        $regression = new RegressionViolation(
            constraint: 'session.idle_timeout',
            expectedDescription: '<= 900s',
            actualDescription: '1800s',
            remediation: 'Reduce timeout.',
        );

        $report = new VerificationReport(
            frameworks: [],
            results: [CheckResult::pass('a', 'ok')],
            regressions: [$regression],
        );

        $diagnostic = new ComplianceDiagnostic();
        $output = $diagnostic->format($report, 'text');

        self::assertStringContainsString('Configuration Regressions', $output['output']);
        self::assertStringContainsString('REGRESSION:', $output['output']);
        self::assertStringContainsString('session.idle_timeout', $output['output']);
    }

    public function testTextFormatWithSkippedChecks(): void
    {
        $report = new VerificationReport(
            frameworks: [],
            results: [
                CheckResult::skip('fips', 'Not required'),
            ],
        );

        $diagnostic = new ComplianceDiagnostic();
        $output = $diagnostic->format($report, 'text');

        self::assertSame(0, $output['exitCode']);
        self::assertStringContainsString('SKIP', $output['output']);
    }
}
