<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceReport;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlMapping;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\ControlVerifier;
use Pulsar\Compliance\VerificationResult;

#[CoversClass(ComplianceReport::class)]
final class ComplianceReportTest extends TestCase
{
    #[Test]
    public function generateProducesValidReport(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(new Control('S-1', 'soc2', 'Access Control', 'Desc', ControlStatus::Implemented, ['auth']));
        $catalog->register(new Control('S-2', 'soc2', 'Monitoring', 'Desc', ControlStatus::Partial, ['metrics']));
        $catalog->register(new Control('S-3', 'soc2', 'Future', 'Desc', ControlStatus::Planned));

        $mapping = new ControlMapping($catalog);
        $mapping->map('auth', 'S-1');
        $mapping->map('metrics', 'S-2');

        $verifier = new ControlVerifier($catalog);
        $verifier->registerVerifier('S-1', static fn(): VerificationResult => VerificationResult::pass('S-1'));
        $verifier->registerVerifier('S-2', static fn(): VerificationResult => VerificationResult::pass('S-2'));

        $report = new ComplianceReport($catalog, $mapping, $verifier);
        $result = $report->generate('soc2');

        self::assertSame('soc2', $result['framework']);
        self::assertArrayHasKey('generated_at', $result);
        self::assertStringContainsString('does not constitute', $result['disclaimer']);

        // Summary
        self::assertSame(3, $result['summary']['total']);
        self::assertSame(1, $result['summary']['implemented']);
        self::assertSame(1, $result['summary']['partial']);
        self::assertSame(1, $result['summary']['planned']);

        // Coverage: (1 implemented + 0.5 partial) / 3 coverable = 50%
        self::assertEqualsWithDelta(50.0, $result['summary']['coverage_percent'], 0.1);

        // Controls detail
        self::assertCount(3, $result['controls']);
        self::assertSame('S-1', $result['controls'][0]['id']);
        self::assertSame(['auth'], $result['controls'][0]['features']);
        self::assertTrue($result['controls'][0]['verified']);
    }

    #[Test]
    public function generateHandlesNotApplicable(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(new Control('S-1', 'soc2', 'Active', 'Desc', ControlStatus::Implemented));
        $catalog->register(new Control('S-2', 'soc2', 'N/A', 'Desc', ControlStatus::NotApplicable));

        $mapping = new ControlMapping($catalog);
        $verifier = new ControlVerifier($catalog);
        $verifier->registerVerifier('S-1', static fn(): VerificationResult => VerificationResult::pass('S-1'));

        $report = new ComplianceReport($catalog, $mapping, $verifier);
        $result = $report->generate('soc2');

        // 1 implemented / 1 coverable (excluding N/A) = 100%
        self::assertSame(1, $result['summary']['not_applicable']);
        self::assertEqualsWithDelta(100.0, $result['summary']['coverage_percent'], 0.1);
    }

    #[Test]
    public function crossFrameworkSummary(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(new Control('S-1', 'soc2', 'SOC2', 'Desc', ControlStatus::Implemented));
        $catalog->register(new Control('H-1', 'hipaa', 'HIPAA', 'Desc', ControlStatus::Partial));

        $mapping = new ControlMapping($catalog);
        $verifier = new ControlVerifier($catalog);

        $report = new ComplianceReport($catalog, $mapping, $verifier);
        $summary = $report->crossFrameworkSummary(['soc2', 'hipaa']);

        self::assertArrayHasKey('soc2', $summary);
        self::assertArrayHasKey('hipaa', $summary);
        self::assertSame(1, $summary['soc2']['total']);
        self::assertSame(1, $summary['hipaa']['total']);
    }

    #[Test]
    public function generateEmptyFramework(): void
    {
        $catalog = new ControlCatalog();
        $mapping = new ControlMapping($catalog);
        $verifier = new ControlVerifier($catalog);

        $report = new ComplianceReport($catalog, $mapping, $verifier);
        $result = $report->generate('empty');

        self::assertSame(0, $result['summary']['total']);
        self::assertSame(0.0, $result['summary']['coverage_percent']);
        self::assertCount(0, $result['controls']);
    }
}
