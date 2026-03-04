<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\Iso13485Mapping;

#[CoversClass(Iso13485Mapping::class)]
final class Iso13485MappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        Iso13485Mapping::register($this->catalog);
    }

    #[Test]
    public function registersEightControls(): void
    {
        $controls = $this->catalog->byFramework('iso13485');

        self::assertCount(8, $controls);
    }

    #[Test]
    public function designControlIsImplemented(): void
    {
        $control = $this->catalog->get('ISO13485-DC-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('7.3', $control->title);
    }

    #[Test]
    public function correctiveActionControlIsImplemented(): void
    {
        $control = $this->catalog->get('ISO13485-CAPA-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('8.5.2', $control->title);
    }

    #[Test]
    public function preventiveActionControlIsImplemented(): void
    {
        $control = $this->catalog->get('ISO13485-CAPA-002');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('8.5.3', $control->title);
    }

    #[Test]
    public function complaintHandlingIsPartial(): void
    {
        $control = $this->catalog->get('ISO13485-COMPLAINT-001');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    #[Test]
    public function riskManagementReferencesIso14971(): void
    {
        $control = $this->catalog->get('ISO13485-RISK-001');

        self::assertNotNull($control);
        self::assertStringContainsString('ISO 14971', $control->description);
    }

    #[Test]
    public function allControlsHaveFrameworkFeatures(): void
    {
        $controls = $this->catalog->byFramework('iso13485');

        foreach ($controls as $control) {
            self::assertNotEmpty($control->frameworkFeatures, "Control {$control->id} has no framework features");
        }
    }
}
