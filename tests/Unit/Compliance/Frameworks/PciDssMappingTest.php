<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\PciDssMapping;

#[CoversClass(PciDssMapping::class)]
final class PciDssMappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        PciDssMapping::register($this->catalog);
    }

    #[Test]
    public function registersFiveControls(): void
    {
        $controls = $this->catalog->byFramework('pci_dss');

        self::assertCount(5, $controls);
    }

    #[Test]
    public function encryptAdministrativeAccessIsImplemented(): void
    {
        $control = $this->catalog->get('Req2.3');

        self::assertNotNull($control);
        self::assertSame('pci_dss', $control->framework);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('tls_enforcement', $control->frameworkFeatures);
        self::assertContains('session_encryption', $control->frameworkFeatures);
        self::assertContains('csrf_protection', $control->frameworkFeatures);
    }

    #[Test]
    public function panProtectionIsImplementedWithTokenization(): void
    {
        $control = $this->catalog->get('Req3.4');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('PAN', $control->title);
        self::assertContains('tokenization', $control->frameworkFeatures);
        self::assertContains('token_vault', $control->frameworkFeatures);
        self::assertContains('envelope_encryption', $control->frameworkFeatures);
    }

    #[Test]
    public function codingVulnerabilitiesAddressedIsImplemented(): void
    {
        $control = $this->catalog->get('Req6.5');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('input_validation', $control->frameworkFeatures);
        self::assertContains('output_escaping', $control->frameworkFeatures);
        self::assertContains('sql_parameterization', $control->frameworkFeatures);
    }

    #[Test]
    public function userIdentificationIsImplemented(): void
    {
        $control = $this->catalog->get('Req8.2');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('authentication', $control->frameworkFeatures);
        self::assertContains('mfa', $control->frameworkFeatures);
    }

    #[Test]
    public function auditTrailsIsImplemented(): void
    {
        $control = $this->catalog->get('Req10.2');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('audit_logging', $control->frameworkFeatures);
        self::assertContains('hmac_chain', $control->frameworkFeatures);
    }

    #[Test]
    public function allControlsAreImplemented(): void
    {
        $controls = $this->catalog->byFramework('pci_dss');

        foreach ($controls as $control) {
            self::assertSame(ControlStatus::Implemented, $control->status, "Control {$control->id} is not Implemented");
        }
    }

    #[Test]
    public function allControlsHaveValidMetadata(): void
    {
        $controls = $this->catalog->byFramework('pci_dss');

        foreach ($controls as $control) {
            self::assertNotEmpty($control->title, "Control {$control->id} has empty title");
            self::assertNotEmpty($control->description, "Control {$control->id} has empty description");
            self::assertNotEmpty($control->frameworkFeatures, "Control {$control->id} has no features");
        }
    }
}
