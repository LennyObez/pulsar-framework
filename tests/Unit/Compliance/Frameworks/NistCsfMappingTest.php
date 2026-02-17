<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\NistCsfMapping;

use function array_map;
use function sprintf;

#[CoversClass(NistCsfMapping::class)]
final class NistCsfMappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        NistCsfMapping::register($this->catalog);
    }

    #[Test]
    public function registersElevenControls(): void
    {
        $controls = $this->catalog->byFramework('nist_csf');

        self::assertCount(11, $controls);
    }

    #[Test]
    public function registersExpectedControlIds(): void
    {
        $controls = $this->catalog->byFramework('nist_csf');
        $ids = array_map(static fn(Control $c): string => $c->id, $controls);

        // Govern
        self::assertContains('NIST-GV.OC', $ids);
        self::assertContains('NIST-GV.RM', $ids);
        // Identify
        self::assertContains('NIST-ID.AM', $ids);
        self::assertContains('NIST-ID.RA', $ids);
        // Protect
        self::assertContains('NIST-PR.AA', $ids);
        self::assertContains('NIST-PR.DS', $ids);
        self::assertContains('NIST-PR.PS', $ids);
        // Detect
        self::assertContains('NIST-DE.CM', $ids);
        self::assertContains('NIST-DE.AE', $ids);
        // Respond
        self::assertContains('NIST-RS.MA', $ids);
        // Recover
        self::assertContains('NIST-RC.RP', $ids);
    }

    #[Test]
    public function allControlsHaveCorrectFramework(): void
    {
        foreach ($this->catalog->all() as $control) {
            self::assertSame('nist_csf', $control->framework);
        }
    }

    #[Test]
    public function allControlsHaveValidStatus(): void
    {
        foreach ($this->catalog->all() as $control) {
            self::assertContains($control->status, [
                ControlStatus::Implemented,
                ControlStatus::Partial,
                ControlStatus::Planned,
            ]);
        }
    }

    #[Test]
    public function allControlsHaveFrameworkFeatures(): void
    {
        foreach ($this->catalog->all() as $control) {
            self::assertNotEmpty(
                $control->frameworkFeatures,
                sprintf('Control %s should list at least one framework feature.', $control->id),
            );
        }
    }

    #[Test]
    public function allControlsHaveNonEmptyTitleAndDescription(): void
    {
        foreach ($this->catalog->all() as $control) {
            self::assertNotEmpty($control->title, sprintf('Control %s must have a title.', $control->id));
            self::assertNotEmpty($control->description, sprintf('Control %s must have a description.', $control->id));
        }
    }

    #[Test]
    public function recoveryControlIsPartial(): void
    {
        $control = $this->catalog->get('NIST-RC.RP');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    #[Test]
    public function identityProtectionControlIsImplemented(): void
    {
        $control = $this->catalog->get('NIST-PR.AA');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('authentication', $control->frameworkFeatures);
        self::assertContains('mfa', $control->frameworkFeatures);
    }

    #[Test]
    public function dataSecurityControlReferencesEncryption(): void
    {
        $control = $this->catalog->get('NIST-PR.DS');

        self::assertNotNull($control);
        self::assertContains('crypto_keyring', $control->frameworkFeatures);
        self::assertContains('tls_enforcement', $control->frameworkFeatures);
    }

    #[Test]
    public function continuousMonitoringControlReferencesAuditLogging(): void
    {
        $control = $this->catalog->get('NIST-DE.CM');

        self::assertNotNull($control);
        self::assertContains('audit_logging', $control->frameworkFeatures);
        self::assertContains('opentelemetry', $control->frameworkFeatures);
    }

    #[Test]
    public function allSixFunctionsAreRepresented(): void
    {
        $controls = $this->catalog->byFramework('nist_csf');
        $ids = array_map(static fn(Control $c): string => $c->id, $controls);

        $prefixes = ['NIST-GV', 'NIST-ID', 'NIST-PR', 'NIST-DE', 'NIST-RS', 'NIST-RC'];

        foreach ($prefixes as $prefix) {
            $found = false;

            foreach ($ids as $id) {
                if (str_starts_with($id, $prefix)) {
                    $found = true;
                    break;
                }
            }

            self::assertTrue($found, sprintf('No control found for NIST CSF function prefix %s.', $prefix));
        }
    }
}
