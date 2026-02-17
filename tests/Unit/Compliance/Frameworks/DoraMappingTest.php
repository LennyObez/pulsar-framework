<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\DoraMapping;

#[CoversClass(DoraMapping::class)]
final class DoraMappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        DoraMapping::register($this->catalog);
    }

    #[Test]
    public function registersEightControls(): void
    {
        $controls = $this->catalog->byFramework('dora');

        self::assertCount(8, $controls);
    }

    #[Test]
    public function riskManagementControlIsImplemented(): void
    {
        $control = $this->catalog->get('DORA-RISK-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Articles 5-16', $control->title);
    }

    #[Test]
    public function incidentManagementControlIsImplemented(): void
    {
        $control = $this->catalog->get('DORA-INC-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function resilienceTestingControlIsImplemented(): void
    {
        $control = $this->catalog->get('DORA-TEST-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function thirdPartyRiskControlIsImplemented(): void
    {
        $control = $this->catalog->get('DORA-TPR-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function businessContinuityControlIsPartial(): void
    {
        $control = $this->catalog->get('DORA-BCM-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    #[Test]
    public function allControlsHaveFrameworkFeatures(): void
    {
        $controls = $this->catalog->byFramework('dora');

        foreach ($controls as $control) {
            self::assertNotEmpty($control->frameworkFeatures, "Control {$control->id} has no features");
        }
    }
}
