<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceReport;
use Pulsar\Compliance\ComplianceStatusProvider;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlMapping;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\ControlVerifier;
use Pulsar\Compliance\VerificationResult;

#[CoversClass(ComplianceStatusProvider::class)]
final class ComplianceStatusProviderTest extends TestCase
{
    private ControlCatalog $catalog;
    private ControlMapping $mapping;
    private ControlVerifier $verifier;
    private ComplianceReport $report;
    private ComplianceStatusProvider $provider;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        $this->mapping = new ControlMapping($this->catalog);
        $this->verifier = new ControlVerifier($this->catalog);
        $this->report = new ComplianceReport($this->catalog, $this->mapping, $this->verifier);
        $this->provider = new ComplianceStatusProvider(
            $this->catalog,
            $this->mapping,
            $this->verifier,
            $this->report,
        );
    }

    #[Test]
    public function verifier_returns_the_injected_verifier(): void
    {
        self::assertSame($this->verifier, $this->provider->verifier());
    }

    #[Test]
    public function overview_with_empty_catalog(): void
    {
        $overview = $this->provider->overview();

        self::assertSame([], $overview['frameworks']);
        self::assertSame(0, $overview['total_controls']);
        self::assertSame([], $overview['coverage']);
        self::assertStringContainsString('does not constitute', $overview['disclaimer']);
    }

    #[Test]
    public function overview_extracts_unique_frameworks(): void
    {
        $this->catalog->register(new Control('C1', 'soc2', 'T1', 'D1', ControlStatus::Implemented));
        $this->catalog->register(new Control('C2', 'hipaa', 'T2', 'D2', ControlStatus::Planned));
        $this->catalog->register(new Control('C3', 'soc2', 'T3', 'D3', ControlStatus::Partial));

        $overview = $this->provider->overview();

        self::assertContains('soc2', $overview['frameworks']);
        self::assertContains('hipaa', $overview['frameworks']);
        self::assertSame(3, $overview['total_controls']);
    }

    #[Test]
    public function framework_detail_returns_report_for_framework(): void
    {
        $this->catalog->register(new Control('C1', 'soc2', 'T1', 'D1', ControlStatus::Implemented));
        $this->verifier->registerVerifier('C1', static fn() => VerificationResult::pass('C1'));

        $detail = $this->provider->frameworkDetail('soc2');

        self::assertSame('soc2', $detail['framework']);
        self::assertSame(1, $detail['summary']['total']);
        self::assertSame(1, $detail['summary']['implemented']);
        self::assertCount(1, $detail['controls']);
        self::assertSame('C1', $detail['controls'][0]['id']);
    }

    #[Test]
    public function feature_coverage_map_delegates_to_mapping(): void
    {
        $this->catalog->register(new Control('C1', 'soc2', 'T1', 'D1', ControlStatus::Implemented));
        $this->mapping->map('encryption', 'C1');

        $map = $this->provider->featureCoverageMap();

        self::assertArrayHasKey('encryption', $map);
        self::assertCount(1, $map['encryption']);
        self::assertSame('C1', $map['encryption'][0]->id);
    }

    #[Test]
    public function overview_coverage_includes_per_framework_stats(): void
    {
        $this->catalog->register(new Control('C1', 'soc2', 'T1', 'D1', ControlStatus::Implemented));
        $this->catalog->register(new Control('C2', 'soc2', 'T2', 'D2', ControlStatus::Partial));
        $this->catalog->register(new Control('C3', 'soc2', 'T3', 'D3', ControlStatus::Planned));

        $overview = $this->provider->overview();

        self::assertArrayHasKey('soc2', $overview['coverage']);
        self::assertSame(3, $overview['coverage']['soc2']['total']);
        self::assertSame(1, $overview['coverage']['soc2']['implemented']);
    }
}
