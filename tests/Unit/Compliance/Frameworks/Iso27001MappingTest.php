<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\Iso27001Mapping;

#[CoversClass(Iso27001Mapping::class)]
final class Iso27001MappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        Iso27001Mapping::register($this->catalog);
    }

    #[Test]
    public function registersTenControls(): void
    {
        $controls = $this->catalog->byFramework('iso27001');

        self::assertCount(10, $controls);
    }

    #[Test]
    public function informationSecurityPolicyIsPartial(): void
    {
        $control = $this->catalog->get('A.5.1');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
        self::assertStringContainsString('Policies', $control->title);
    }

    #[Test]
    public function userEndpointDevicesIsImplemented(): void
    {
        $control = $this->catalog->get('A.8.1');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function accessRestrictionIsImplemented(): void
    {
        $control = $this->catalog->get('A.8.3');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function cryptographyControlIsImplemented(): void
    {
        $control = $this->catalog->get('A.8.24');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Cryptography', $control->title);
    }

    #[Test]
    public function loggingControlIsImplemented(): void
    {
        $control = $this->catalog->get('A.8.15');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function secureDevelopmentLifeCycleIsImplemented(): void
    {
        $control = $this->catalog->get('A.8.25');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function allControlsHaveFrameworkFeatures(): void
    {
        $controls = $this->catalog->byFramework('iso27001');

        foreach ($controls as $control) {
            self::assertNotEmpty($control->frameworkFeatures, "Control {$control->id} has no features");
        }
    }
}
