<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Verification\ConflictDetector;

#[CoversClass(ConflictDetector::class)]
final class ConflictDetectorTest extends TestCase
{
    public function testNoConflictsForSingleFramework(): void
    {
        $detector = new ConflictDetector();
        $conflicts = $detector->detect([ComplianceFramework::Gdpr]);

        self::assertSame([], $conflicts);
    }

    public function testNoConflictsForNonConflictingFrameworks(): void
    {
        $detector = new ConflictDetector();
        $conflicts = $detector->detect([
            ComplianceFramework::PciDss,
            ComplianceFramework::Hipaa,
        ]);

        self::assertSame([], $conflicts);
    }

    public function testDetectsGdprPciDssConflict(): void
    {
        $detector = new ConflictDetector();
        $conflicts = $detector->detect([
            ComplianceFramework::Gdpr,
            ComplianceFramework::PciDss,
        ]);

        self::assertCount(1, $conflicts);
        self::assertSame(ComplianceFramework::Gdpr, $conflicts[0]->frameworkA);
        self::assertSame(ComplianceFramework::PciDss, $conflicts[0]->frameworkB);
        self::assertNotEmpty($conflicts[0]->resolution);
        self::assertNotEmpty($conflicts[0]->resolutionSteps);
    }

    public function testDetectsGdprHipaaConflict(): void
    {
        $detector = new ConflictDetector();
        $conflicts = $detector->detect([
            ComplianceFramework::Gdpr,
            ComplianceFramework::Hipaa,
        ]);

        self::assertCount(1, $conflicts);
        self::assertSame(ComplianceFramework::Hipaa, $conflicts[0]->frameworkB);
    }

    public function testDetectsGdprEidasConflict(): void
    {
        $detector = new ConflictDetector();
        $conflicts = $detector->detect([
            ComplianceFramework::Gdpr,
            ComplianceFramework::Eidas,
        ]);

        self::assertCount(1, $conflicts);
        self::assertSame(ComplianceFramework::Eidas, $conflicts[0]->frameworkB);
    }

    public function testDetectsPsd2GdprConflict(): void
    {
        $detector = new ConflictDetector();
        $conflicts = $detector->detect([
            ComplianceFramework::Psd2,
            ComplianceFramework::Gdpr,
        ]);

        self::assertCount(1, $conflicts);
        self::assertSame(ComplianceFramework::Psd2, $conflicts[0]->frameworkA);
    }

    public function testDetectsDoraGdprConflict(): void
    {
        $detector = new ConflictDetector();
        $conflicts = $detector->detect([
            ComplianceFramework::Dora,
            ComplianceFramework::Gdpr,
        ]);

        self::assertCount(1, $conflicts);
        self::assertSame(ComplianceFramework::Dora, $conflicts[0]->frameworkA);
    }

    public function testDetectsSwiftCspGdprConflict(): void
    {
        $detector = new ConflictDetector();
        $conflicts = $detector->detect([
            ComplianceFramework::SwiftCsp,
            ComplianceFramework::Gdpr,
        ]);

        self::assertCount(1, $conflicts);
        self::assertSame(ComplianceFramework::SwiftCsp, $conflicts[0]->frameworkA);
    }

    public function testMultipleConflictsForManyFrameworks(): void
    {
        $detector = new ConflictDetector();
        $conflicts = $detector->detect([
            ComplianceFramework::Gdpr,
            ComplianceFramework::PciDss,
            ComplianceFramework::Hipaa,
            ComplianceFramework::Dora,
        ]);

        // GDPR+PCI, GDPR+HIPAA, DORA+GDPR = 3
        self::assertCount(3, $conflicts);
    }

    public function testEmptyFrameworksNoConflicts(): void
    {
        $detector = new ConflictDetector();
        self::assertSame([], $detector->detect([]));
    }
}
