<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\Verification\AuditReportGenerator;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Compliance\Verification\ConflictReport;
use Pulsar\Compliance\Verification\RegressionViolation;
use Pulsar\Compliance\Verification\VerificationReport;

use function count;

#[CoversClass(AuditReportGenerator::class)]
final class AuditReportGeneratorTest extends TestCase
{
    public function testGenerateStructure(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr, ComplianceFramework::PciDss]);
        $generator = new AuditReportGenerator($profile);

        $report = new VerificationReport(
            frameworks: $profile->enabledFrameworks,
            results: [
                CheckResult::pass('enc', 'ok', ComplianceCheckDomain::Encryption),
                CheckResult::fail('tls', 'missing', ComplianceCheckDomain::TransportSecurity),
            ],
        );

        /** @var array<string, mixed> $output */
        $output = $generator->generate($report);

        self::assertSame('pre_audit_compliance_report', $output['report_type']);
        self::assertSame('1.0.0-rc.11', $output['framework_version']);
        self::assertArrayHasKey('generated_at', $output);

        /** @var list<array<string, string>> $frameworks */
        $frameworks = $output['frameworks'];
        self::assertCount(2, $frameworks);
        self::assertSame('gdpr', $frameworks[0]['id']);
        self::assertSame('EU General Data Protection Regulation', $frameworks[0]['name']);
    }

    public function testGenerateIncludesProfileConstraints(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $generator = new AuditReportGenerator($profile);

        $report = new VerificationReport(frameworks: $profile->enabledFrameworks, results: []);
        /** @var array<string, mixed> $output */
        $output = $generator->generate($report);

        /** @var array<string, mixed> $constraints */
        $constraints = $output['profile_constraints'];
        self::assertSame(12, $constraints['password_min_length']);
        self::assertSame(900, $constraints['session_idle_timeout']);
        self::assertTrue($constraints['encryption_at_rest']);
        self::assertSame('always', $constraints['mfa_requirement']);
    }

    public function testGenerateIncludesVerificationSummary(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $generator = new AuditReportGenerator($profile);

        $report = new VerificationReport(
            frameworks: $profile->enabledFrameworks,
            results: [
                CheckResult::pass('a', 'ok'),
                CheckResult::fail('b', 'bad'),
                CheckResult::skip('c', 'skipped'),
            ],
        );

        /** @var array<string, mixed> $output */
        $output = $generator->generate($report);
        /** @var array<string, mixed> $summary */
        $summary = $output['verification_summary'];

        self::assertSame(3, $summary['total_checks']);
        self::assertSame(1, $summary['passed']);
        self::assertSame(1, $summary['failed']);
        self::assertSame(1, $summary['skipped']);
        self::assertTrue($summary['has_failures']);
    }

    public function testGenerateIncludesConflictsAndRegressions(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $generator = new AuditReportGenerator($profile);

        $conflict = new ConflictReport(
            frameworkA: ComplianceFramework::Gdpr,
            frameworkB: ComplianceFramework::PciDss,
            requirementA: 'a',
            requirementB: 'b',
            description: 'c',
            resolution: 'd',
        );

        $regression = new RegressionViolation('x', 'y', 'z', 'fix');

        $report = new VerificationReport(
            frameworks: $profile->enabledFrameworks,
            results: [],
            conflicts: [$conflict],
            regressions: [$regression],
        );

        /** @var array<string, mixed> $output */
        $output = $generator->generate($report);

        /** @var list<mixed> $conflicts */
        $conflicts = $output['cross_framework_conflicts'];
        self::assertCount(1, $conflicts);
        /** @var list<mixed> $regressions */
        $regressions = $output['configuration_regressions'];
        self::assertCount(1, $regressions);
    }

    public function testGenerateIncludesControlCoverageMap(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $generator = new AuditReportGenerator($profile);

        $report = new VerificationReport(frameworks: $profile->enabledFrameworks, results: []);
        /** @var array<string, mixed> $output */
        $output = $generator->generate($report);

        /** @var array<string, array<string, mixed>> $coverageMap */
        $coverageMap = $output['control_coverage_map'];
        self::assertNotEmpty($coverageMap);
        self::assertArrayHasKey('encryption.at_rest', $coverageMap);
        self::assertArrayHasKey('component', $coverageMap['encryption.at_rest']);
        self::assertArrayHasKey('file', $coverageMap['encryption.at_rest']);
    }

    public function testGenerateIncludesDisclaimer(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $generator = new AuditReportGenerator($profile);

        $report = new VerificationReport(frameworks: $profile->enabledFrameworks, results: []);
        /** @var array<string, mixed> $output */
        $output = $generator->generate($report);

        self::assertIsString($output['disclaimer']);
        self::assertStringContainsString('does not constitute', $output['disclaimer']);
    }

    public function testAllFrameworkDisplayNames(): void
    {
        // Test with all frameworks to ensure frameworkDisplayName covers them all
        $frameworks = ComplianceFramework::cases();
        $profile = $this->createProfile($frameworks);
        $generator = new AuditReportGenerator($profile);

        $report = new VerificationReport(frameworks: $frameworks, results: []);
        /** @var array<string, mixed> $output */
        $output = $generator->generate($report);

        /** @var list<array<string, string>> $outputFrameworks */
        $outputFrameworks = $output['frameworks'];
        self::assertCount(count($frameworks), $outputFrameworks);

        foreach ($outputFrameworks as $fw) {
            self::assertNotEmpty($fw['name']);
            self::assertNotEmpty($fw['id']);
        }
    }

    /**
     * @param list<ComplianceFramework> $frameworks
     */
    private function createProfile(array $frameworks): ComplianceProfile
    {
        return new ComplianceProfile(
            enabledFrameworks: $frameworks,
            passwordMinLength: 12,
            sessionIdleTimeout: 900,
            breachNotificationHours: 72,
            auditRetentionDays: 365,
            dataRetentionDays: 2190,
            mfaRequirement: 'always',
            encryptionAtRest: true,
            encryptionInTransit: true,
            tamperEvidentAudit: true,
            explicitConsent: true,
            consentWithdrawal: true,
            individualNotification: true,
            breachRegister: true,
        );
    }
}
