<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\Nis2Mapping;

#[CoversClass(Nis2Mapping::class)]
final class Nis2MappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        Nis2Mapping::register($this->catalog);
    }

    #[Test]
    public function registersEightControls(): void
    {
        $controls = $this->catalog->byFramework('nis2');

        self::assertCount(8, $controls);
    }

    #[Test]
    public function riskAnalysisControlIsImplemented(): void
    {
        $control = $this->catalog->get('NIS2-Art21(a)');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Risk Analysis', $control->title);
    }

    #[Test]
    public function incidentHandlingIsImplemented(): void
    {
        $control = $this->catalog->get('NIS2-Art21(b)');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function supplyChainSecurityIsImplemented(): void
    {
        $control = $this->catalog->get('NIS2-Art21(d)');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function vulnerabilityHandlingIsPartial(): void
    {
        $control = $this->catalog->get('NIS2-Art21(e)');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    #[Test]
    public function cyberHygieneIsPartial(): void
    {
        $control = $this->catalog->get('NIS2-Art21(g)');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    #[Test]
    public function cryptographyControlIsImplemented(): void
    {
        $control = $this->catalog->get('NIS2-Art21(h)');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function incidentReportingIsImplemented(): void
    {
        $control = $this->catalog->get('NIS2-Art23');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function allControlsHaveFrameworkFeatures(): void
    {
        $controls = $this->catalog->byFramework('nis2');

        foreach ($controls as $control) {
            self::assertNotEmpty($control->frameworkFeatures, "Control {$control->id} has no features");
        }
    }
}
