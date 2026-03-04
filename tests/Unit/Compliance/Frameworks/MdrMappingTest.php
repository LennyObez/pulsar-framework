<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\MdrMapping;

#[CoversClass(MdrMapping::class)]
final class MdrMappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        MdrMapping::register($this->catalog);
    }

    #[Test]
    public function registersEightControls(): void
    {
        $controls = $this->catalog->byFramework('mdr');

        self::assertCount(8, $controls);
    }

    #[Test]
    public function udiControlIsImplemented(): void
    {
        $control = $this->catalog->get('MDR-UDI-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Unique Device Identification', $control->title);
    }

    #[Test]
    public function vigilanceControlIsImplemented(): void
    {
        $control = $this->catalog->get('MDR-VIG-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Article 87', $control->title);
    }

    #[Test]
    public function clinicalInvestigationControlIsImplemented(): void
    {
        $control = $this->catalog->get('MDR-CLIN-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function eudamedControlIsPartial(): void
    {
        $control = $this->catalog->get('MDR-EUDAMED-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    #[Test]
    public function allControlsHaveFrameworkFeatures(): void
    {
        $controls = $this->catalog->byFramework('mdr');

        foreach ($controls as $control) {
            self::assertNotEmpty($control->frameworkFeatures, "Control {$control->id} has no framework features");
        }
    }
}
