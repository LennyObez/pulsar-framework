<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\GdprMapping;

#[CoversClass(GdprMapping::class)]
final class GdprMappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        GdprMapping::register($this->catalog);
    }

    #[Test]
    public function registersFiveControls(): void
    {
        $controls = $this->catalog->byFramework('gdpr');

        self::assertCount(5, $controls);
    }

    #[Test]
    public function integrityAndConfidentialityIsImplemented(): void
    {
        $control = $this->catalog->get('Art5(1)(f)');

        self::assertNotNull($control);
        self::assertSame('gdpr', $control->framework);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Integrity', $control->title);
        self::assertContains('crypto_keyring', $control->frameworkFeatures);
        self::assertContains('authorization', $control->frameworkFeatures);
    }

    #[Test]
    public function dataProtectionByDesignIsImplemented(): void
    {
        $control = $this->catalog->get('Art25');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('pseudonymization', $control->frameworkFeatures);
        self::assertContains('data_classification', $control->frameworkFeatures);
    }

    #[Test]
    public function recordsOfProcessingIsImplemented(): void
    {
        $control = $this->catalog->get('Art30');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('audit_logging', $control->frameworkFeatures);
        self::assertContains('compliance_events', $control->frameworkFeatures);
    }

    #[Test]
    public function securityOfProcessingIsImplemented(): void
    {
        $control = $this->catalog->get('Art32');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('crypto_keyring', $control->frameworkFeatures);
        self::assertContains('observability', $control->frameworkFeatures);
    }

    #[Test]
    public function breachNotificationIsPartial(): void
    {
        $control = $this->catalog->get('Art33');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
        self::assertContains('breach_detection', $control->frameworkFeatures);
    }

    #[Test]
    public function allControlsHaveNonEmptyTitleAndDescription(): void
    {
        $controls = $this->catalog->byFramework('gdpr');

        foreach ($controls as $control) {
            self::assertNotEmpty($control->title, "Control {$control->id} has empty title");
            self::assertNotEmpty($control->description, "Control {$control->id} has empty description");
            self::assertNotEmpty($control->frameworkFeatures, "Control {$control->id} has no framework features");
        }
    }
}
