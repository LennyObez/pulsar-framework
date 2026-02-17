<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\EidasMapping;

#[CoversClass(EidasMapping::class)]
final class EidasMappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        EidasMapping::register($this->catalog);
    }

    #[Test]
    public function registersEightControls(): void
    {
        $controls = $this->catalog->byFramework('eidas');

        self::assertCount(8, $controls);
    }

    #[Test]
    public function assuranceLevelsControlIsImplemented(): void
    {
        $control = $this->catalog->get('eIDAS-Art8');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Assurance Levels', $control->title);
    }

    #[Test]
    public function electronicSignaturesControlIsImplemented(): void
    {
        $control = $this->catalog->get('eIDAS-Art25-34');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function electronicSealsControlIsImplemented(): void
    {
        $control = $this->catalog->get('eIDAS-Art35-40');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function timestampControlIsImplemented(): void
    {
        $control = $this->catalog->get('eIDAS-Art41-42');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function qualifiedTspControlIsPartial(): void
    {
        $control = $this->catalog->get('eIDAS-Art24');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    #[Test]
    public function mutualRecognitionControlIsPartial(): void
    {
        $control = $this->catalog->get('eIDAS-Art17');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    #[Test]
    public function allControlsHaveFrameworkFeatures(): void
    {
        $controls = $this->catalog->byFramework('eidas');

        foreach ($controls as $control) {
            self::assertNotEmpty($control->frameworkFeatures, "Control {$control->id} has no features");
        }
    }
}
