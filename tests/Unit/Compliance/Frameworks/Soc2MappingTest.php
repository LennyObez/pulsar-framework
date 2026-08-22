<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\Soc2Mapping;

use function count;

#[CoversClass(Soc2Mapping::class)]
final class Soc2MappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        Soc2Mapping::register($this->catalog);
    }

    #[Test]
    public function registersFullTscCoverage(): void
    {
        $controls = $this->catalog->byFramework('soc2');

        // Full TSC coverage: CC1-CC9, A1, PI1, C1, P1
        self::assertGreaterThanOrEqual(30, count($controls));
    }

    #[Test]
    public function coversAllTscCategories(): void
    {
        $controls = $this->catalog->byFramework('soc2');
        $prefixes = [];

        foreach ($controls as $control) {
            $prefix = explode('.', $control->id)[0];
            $prefixes[$prefix] = true;
        }

        self::assertArrayHasKey('CC1', $prefixes, 'CC1 (Control Environment) missing');
        self::assertArrayHasKey('CC2', $prefixes, 'CC2 (Communication) missing');
        self::assertArrayHasKey('CC3', $prefixes, 'CC3 (Risk Assessment) missing');
        self::assertArrayHasKey('CC4', $prefixes, 'CC4 (Monitoring) missing');
        self::assertArrayHasKey('CC5', $prefixes, 'CC5 (Control Activities) missing');
        self::assertArrayHasKey('CC6', $prefixes, 'CC6 (Access Controls) missing');
        self::assertArrayHasKey('CC7', $prefixes, 'CC7 (System Operations) missing');
        self::assertArrayHasKey('CC8', $prefixes, 'CC8 (Change Management) missing');
        self::assertArrayHasKey('CC9', $prefixes, 'CC9 (Risk Mitigation) missing');
        self::assertArrayHasKey('A1', $prefixes, 'A1 (Availability) missing');
        self::assertArrayHasKey('PI1', $prefixes, 'PI1 (Processing Integrity) missing');
        self::assertArrayHasKey('C1', $prefixes, 'C1 (Confidentiality) missing');
        self::assertArrayHasKey('P1', $prefixes, 'P1 (Privacy) missing');
    }

    #[Test]
    public function integrityAndEthicalValuesIsImplemented(): void
    {
        $control = $this->catalog->get('CC1.1');

        self::assertNotNull($control);
        self::assertSame('soc2', $control->framework);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('audit_logging', $control->frameworkFeatures);
        self::assertContains('hmac_chain', $control->frameworkFeatures);
    }

    #[Test]
    public function logicalAccessControlsIsImplemented(): void
    {
        $control = $this->catalog->get('CC6.1');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('authentication', $control->frameworkFeatures);
        self::assertContains('authorization', $control->frameworkFeatures);
        self::assertContains('session_management', $control->frameworkFeatures);
    }

    #[Test]
    public function rbacIsImplemented(): void
    {
        $control = $this->catalog->get('CC6.3');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('rbac', $control->frameworkFeatures);
        self::assertContains('permission_gates', $control->frameworkFeatures);
    }

    #[Test]
    public function systemMonitoringIsImplemented(): void
    {
        $control = $this->catalog->get('CC7.2');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('observability', $control->frameworkFeatures);
        self::assertContains('metrics', $control->frameworkFeatures);
        self::assertContains('health_checks', $control->frameworkFeatures);
    }

    #[Test]
    public function changeManagementIsPartial(): void
    {
        $control = $this->catalog->get('CC8.1');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
        self::assertContains('deployment', $control->frameworkFeatures);
        self::assertContains('integrity_verification', $control->frameworkFeatures);
    }

    #[Test]
    public function mostControlsAreImplemented(): void
    {
        $controls = $this->catalog->byFramework('soc2');

        $implemented = array_filter($controls, static fn($c) => $c->status === ControlStatus::Implemented);

        // At least 80% should be Implemented
        $ratio = count($implemented) / count($controls);
        self::assertGreaterThan(0.8, $ratio, 'Less than 80% of SOC 2 controls are Implemented');
    }

    #[Test]
    public function controlIdsAreUnique(): void
    {
        $controls = $this->catalog->byFramework('soc2');
        $ids = [];

        foreach ($controls as $control) {
            self::assertArrayNotHasKey(
                $control->id,
                $ids,
                "Duplicate control ID: {$control->id}",
            );
            $ids[$control->id] = true;
        }
    }

    #[Test]
    public function allControlsHaveValidMetadata(): void
    {
        $controls = $this->catalog->byFramework('soc2');

        foreach ($controls as $control) {
            self::assertNotEmpty($control->title, "Control {$control->id} has empty title");
            self::assertNotEmpty($control->description, "Control {$control->id} has empty description");
            self::assertNotEmpty($control->frameworkFeatures, "Control {$control->id} has no features");
        }
    }
}
