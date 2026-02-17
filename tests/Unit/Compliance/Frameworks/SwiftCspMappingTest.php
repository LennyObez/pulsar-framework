<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\SwiftCspMapping;

#[CoversClass(SwiftCspMapping::class)]
final class SwiftCspMappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        SwiftCspMapping::register($this->catalog);
    }

    #[Test]
    public function registers31Controls(): void
    {
        $controls = $this->catalog->byFramework('swift_csp');

        self::assertCount(31, $controls);
    }

    #[Test]
    public function environmentProtectionControlIsImplemented(): void
    {
        $control = $this->catalog->get('SWIFT-1.1');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Environment Protection', $control->title);
    }

    #[Test]
    public function passwordPolicyControlIsImplemented(): void
    {
        $control = $this->catalog->get('SWIFT-4.1');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Password Policy', $control->title);
    }

    #[Test]
    public function mfaControlIsImplemented(): void
    {
        $control = $this->catalog->get('SWIFT-4.2');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function loggingAndMonitoringControlIsImplemented(): void
    {
        $control = $this->catalog->get('SWIFT-6.4');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Logging and Monitoring', $control->title);
    }

    #[Test]
    public function incidentResponseControlIsImplemented(): void
    {
        $control = $this->catalog->get('SWIFT-7.1');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function physicalSecurityIsNotApplicable(): void
    {
        $control = $this->catalog->get('SWIFT-3.1');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::NotApplicable, $control->status);
    }

    #[Test]
    public function securityUpdatesControlIsPartial(): void
    {
        $control = $this->catalog->get('SWIFT-2.2');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    #[Test]
    public function vulnerabilityScanningControlIsPartial(): void
    {
        $control = $this->catalog->get('SWIFT-2.7');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    #[Test]
    public function allControlsHaveFrameworkFeatures(): void
    {
        $controls = $this->catalog->byFramework('swift_csp');

        foreach ($controls as $control) {
            self::assertNotEmpty($control->frameworkFeatures, "Control {$control->id} has no features");
        }
    }

    #[Test]
    public function threeControlsAreNotApplicable(): void
    {
        $notApplicable = ['SWIFT-3.1', 'SWIFT-5.3A', 'SWIFT-7.2'];

        foreach ($notApplicable as $id) {
            $control = $this->catalog->get($id);
            self::assertNotNull($control, "Control {$id} not found");
            self::assertSame(ControlStatus::NotApplicable, $control->status, "Control {$id} should be NotApplicable");
        }
    }
}
