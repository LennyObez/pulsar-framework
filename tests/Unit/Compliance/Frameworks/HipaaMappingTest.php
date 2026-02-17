<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\HipaaMapping;

#[CoversClass(HipaaMapping::class)]
final class HipaaMappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        HipaaMapping::register($this->catalog);
    }

    #[Test]
    public function registersNineControls(): void
    {
        $controls = $this->catalog->byFramework('hipaa');

        self::assertCount(9, $controls);
    }

    #[Test]
    public function accessControlIsImplemented(): void
    {
        $control = $this->catalog->get('164.312(a)(1)');

        self::assertNotNull($control);
        self::assertSame('hipaa', $control->framework);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Access Control', $control->title);
        self::assertContains('authentication', $control->frameworkFeatures);
        self::assertContains('rbac', $control->frameworkFeatures);
    }

    #[Test]
    public function encryptionControlIsImplemented(): void
    {
        $control = $this->catalog->get('164.312(a)(2)(iv)');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('crypto_keyring', $control->frameworkFeatures);
        self::assertContains('envelope_encryption', $control->frameworkFeatures);
    }

    #[Test]
    public function auditControlIsImplemented(): void
    {
        $control = $this->catalog->get('164.312(b)');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('audit_logging', $control->frameworkFeatures);
        self::assertContains('hmac_chain', $control->frameworkFeatures);
    }

    #[Test]
    public function integrityControlIsImplemented(): void
    {
        $control = $this->catalog->get('164.312(c)(1)');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('integrity_verification', $control->frameworkFeatures);
    }

    #[Test]
    public function transmissionSecurityIsPartial(): void
    {
        $control = $this->catalog->get('164.312(e)(1)');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
        self::assertContains('tls_enforcement', $control->frameworkFeatures);
    }

    #[Test]
    public function hipaa2026MfaControlIsImplemented(): void
    {
        $control = $this->catalog->get('164.312(d)-2026');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('MFA', $control->title);
        self::assertContains('mfa', $control->frameworkFeatures);
    }

    #[Test]
    public function hipaa2026EncryptionMandatoryIsImplemented(): void
    {
        $control = $this->catalog->get('164.312(a)(2)(iv)-2026');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Mandatory', $control->title);
        self::assertContains('tls_enforcement', $control->frameworkFeatures);
    }

    #[Test]
    public function hipaa2026ContingencyPlanIsPartial(): void
    {
        $control = $this->catalog->get('164.308(a)(7)-2026');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
        self::assertStringContainsString('72-Hour', $control->title);
    }

    #[Test]
    public function hipaa2026AssetInventoryIsPartial(): void
    {
        $control = $this->catalog->get('164.312-2026-asset');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
        self::assertContains('extension_registry', $control->frameworkFeatures);
    }

    #[Test]
    public function allControlsHaveValidMetadata(): void
    {
        $controls = $this->catalog->byFramework('hipaa');

        foreach ($controls as $control) {
            self::assertNotEmpty($control->title, "Control {$control->id} has empty title");
            self::assertNotEmpty($control->description, "Control {$control->id} has empty description");
            self::assertNotEmpty($control->frameworkFeatures, "Control {$control->id} has no features");
        }
    }

    #[Test]
    public function distinguishesOriginalAndNprmControls(): void
    {
        $controls = $this->catalog->byFramework('hipaa');

        $nprmControls = array_filter(
            $controls,
            static fn($c) => str_contains($c->id, '2026'),
        );

        $originalControls = array_filter(
            $controls,
            static fn($c) => !str_contains($c->id, '2026'),
        );

        self::assertCount(4, $nprmControls);
        self::assertCount(5, $originalControls);
    }
}
