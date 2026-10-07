<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Verification\ConflictReport;

#[CoversClass(ConflictReport::class)]
final class ConflictReportTest extends TestCase
{
    public function testConstruction(): void
    {
        $report = new ConflictReport(
            frameworkA: ComplianceFramework::Gdpr,
            frameworkB: ComplianceFramework::PciDss,
            requirementA: 'Art. 17',
            requirementB: 'Req. 10.7',
            description: 'Erasure vs retention',
            resolution: 'Pseudonymize',
            resolutionSteps: ['Step 1', 'Step 2'],
        );

        self::assertSame(ComplianceFramework::Gdpr, $report->frameworkA);
        self::assertSame(ComplianceFramework::PciDss, $report->frameworkB);
        self::assertSame('Art. 17', $report->requirementA);
        self::assertSame('Req. 10.7', $report->requirementB);
    }

    public function testToArray(): void
    {
        $report = new ConflictReport(
            frameworkA: ComplianceFramework::Gdpr,
            frameworkB: ComplianceFramework::Hipaa,
            requirementA: 'Art. 5(1)(e)',
            requirementB: '45 CFR 164.530(j)',
            description: 'Storage limitation vs retention',
            resolution: 'Apply HIPAA period',
            resolutionSteps: ['Apply 6-year retention'],
        );

        $array = $report->toArray();

        self::assertSame('gdpr', $array['framework_a']);
        self::assertSame('hipaa', $array['framework_b']);
        self::assertSame('Art. 5(1)(e)', $array['requirement_a']);
        self::assertSame('Apply HIPAA period', $array['resolution']);
        self::assertSame(['Apply 6-year retention'], $array['resolution_steps']);
    }

    public function testDefaultEmptyResolutionSteps(): void
    {
        $report = new ConflictReport(
            frameworkA: ComplianceFramework::Gdpr,
            frameworkB: ComplianceFramework::PciDss,
            requirementA: 'a',
            requirementB: 'b',
            description: 'c',
            resolution: 'd',
        );

        self::assertSame([], $report->resolutionSteps);
    }
}
