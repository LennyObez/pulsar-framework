<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlMapping;
use Pulsar\Compliance\ControlStatus;

#[CoversClass(ControlMapping::class)]
#[CoversClass(ControlCatalog::class)]
#[CoversClass(Control::class)]
#[CoversClass(ControlStatus::class)]
final class ControlMappingTest extends TestCase
{
    private ControlCatalog $catalog;
    private ControlMapping $mapping;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        $this->mapping = new ControlMapping($this->catalog);
    }

    #[Test]
    public function controlsForFeatureReturnsMatchingControls(): void
    {
        $control1 = new Control(
            id: 'C-1',
            framework: 'soc2',
            title: 'Access Control',
            description: 'Access control requirement.',
            status: ControlStatus::Implemented,
        );
        $control2 = new Control(
            id: 'C-2',
            framework: 'hipaa',
            title: 'Audit Logs',
            description: 'Audit logging requirement.',
            status: ControlStatus::Implemented,
        );

        $this->catalog->register($control1);
        $this->catalog->register($control2);

        $this->mapping->map('authentication', 'C-1');
        $this->mapping->map('authentication', 'C-2');

        $controls = $this->mapping->controlsForFeature('authentication');

        self::assertCount(2, $controls);
        self::assertSame('C-1', $controls[0]->id);
        self::assertSame('C-2', $controls[1]->id);
    }

    #[Test]
    public function featuresForControlReturnsMatchingFeatures(): void
    {
        $this->catalog->register(new Control(
            id: 'C-1',
            framework: 'soc2',
            title: 'Test',
            description: 'Test control.',
            status: ControlStatus::Implemented,
        ));

        $this->mapping->map('authentication', 'C-1');
        $this->mapping->map('rbac', 'C-1');
        $this->mapping->map('session_management', 'C-1');

        $features = $this->mapping->featuresForControl('C-1');

        self::assertCount(3, $features);
        self::assertContains('authentication', $features);
        self::assertContains('rbac', $features);
        self::assertContains('session_management', $features);
    }

    #[Test]
    public function controlsForFeatureReturnsEmptyForUnknownFeature(): void
    {
        self::assertSame([], $this->mapping->controlsForFeature('nonexistent'));
    }

    #[Test]
    public function featuresForControlReturnsEmptyForUnknownControl(): void
    {
        self::assertSame([], $this->mapping->featuresForControl('nonexistent'));
    }

    #[Test]
    public function duplicateMappingsAreIgnored(): void
    {
        $this->catalog->register(new Control(
            id: 'C-1',
            framework: 'soc2',
            title: 'Test',
            description: 'Test control.',
            status: ControlStatus::Implemented,
        ));

        $this->mapping->map('auth', 'C-1');
        $this->mapping->map('auth', 'C-1');
        $this->mapping->map('auth', 'C-1');

        self::assertCount(1, $this->mapping->controlsForFeature('auth'));
        self::assertCount(1, $this->mapping->featuresForControl('C-1'));
    }

    #[Test]
    public function controlsForFeatureSkipsMissingCatalogEntries(): void
    {
        // Map a feature to a control ID that does not exist in the catalog
        $this->mapping->map('auth', 'MISSING-1');

        self::assertSame([], $this->mapping->controlsForFeature('auth'));
    }

    #[Test]
    public function coverageReportReturnsCorrectStructure(): void
    {
        $this->catalog->register(new Control(
            id: 'S-1',
            framework: 'soc2',
            title: 'Implemented 1',
            description: 'Implemented.',
            status: ControlStatus::Implemented,
        ));
        $this->catalog->register(new Control(
            id: 'S-2',
            framework: 'soc2',
            title: 'Implemented 2',
            description: 'Also implemented.',
            status: ControlStatus::Implemented,
        ));
        $this->catalog->register(new Control(
            id: 'S-3',
            framework: 'soc2',
            title: 'Partial',
            description: 'Partially implemented.',
            status: ControlStatus::Partial,
        ));
        $this->catalog->register(new Control(
            id: 'S-4',
            framework: 'soc2',
            title: 'Planned',
            description: 'Planned for future.',
            status: ControlStatus::Planned,
        ));

        $report = $this->mapping->coverageReport();

        self::assertArrayHasKey('soc2', $report);

        $soc2 = $report['soc2'];

        self::assertSame(4, $soc2['total']);
        self::assertSame(2, $soc2['implemented']);
        self::assertSame(1, $soc2['partial']);
        self::assertSame(1, $soc2['planned']);

        // Coverage: (2 implemented + 0.5 * 1 partial) / 4 total = 2.5 / 4 = 62.5%
        self::assertSame(62.5, $soc2['coverage_percent']);
    }

    #[Test]
    public function coverageReportExcludesNotApplicableFromPercentage(): void
    {
        $this->catalog->register(new Control(
            id: 'H-1',
            framework: 'hipaa',
            title: 'Implemented',
            description: 'Implemented.',
            status: ControlStatus::Implemented,
        ));
        $this->catalog->register(new Control(
            id: 'H-2',
            framework: 'hipaa',
            title: 'N/A',
            description: 'Not applicable.',
            status: ControlStatus::NotApplicable,
        ));

        $report = $this->mapping->coverageReport();

        $hipaa = $report['hipaa'];

        self::assertSame(2, $hipaa['total']);
        self::assertSame(1, $hipaa['implemented']);
        self::assertSame(0, $hipaa['partial']);
        self::assertSame(0, $hipaa['planned']);

        // Coverage: 1 implemented / 1 effective total (2 - 1 N/A) = 100%
        self::assertSame(100.0, $hipaa['coverage_percent']);
    }

    #[Test]
    public function coverageReportHandlesMultipleFrameworks(): void
    {
        $this->catalog->register(new Control(
            id: 'A-1',
            framework: 'soc2',
            title: 'SOC2',
            description: 'SOC2 control.',
            status: ControlStatus::Implemented,
        ));
        $this->catalog->register(new Control(
            id: 'B-1',
            framework: 'gdpr',
            title: 'GDPR',
            description: 'GDPR control.',
            status: ControlStatus::Planned,
        ));

        $report = $this->mapping->coverageReport();

        self::assertCount(2, $report);
        self::assertArrayHasKey('soc2', $report);
        self::assertArrayHasKey('gdpr', $report);
        self::assertSame(100.0, $report['soc2']['coverage_percent']);
        self::assertSame(0.0, $report['gdpr']['coverage_percent']);
    }

    #[Test]
    public function coverageReportReturnsEmptyForEmptyCatalog(): void
    {
        self::assertSame([], $this->mapping->coverageReport());
    }
}
