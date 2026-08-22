<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\EidasMapping;
use Pulsar\Compliance\Frameworks\GdprMapping;
use Pulsar\Compliance\Frameworks\HipaaMapping;
use Pulsar\Compliance\Frameworks\Iso27001Mapping;
use Pulsar\Compliance\Frameworks\Nis2Mapping;
use Pulsar\Compliance\Frameworks\PciDssMapping;
use Pulsar\Compliance\Frameworks\Soc2Mapping;

use function sprintf;

#[CoversClass(Soc2Mapping::class)]
#[CoversClass(HipaaMapping::class)]
#[CoversClass(GdprMapping::class)]
#[CoversClass(PciDssMapping::class)]
#[CoversClass(Nis2Mapping::class)]
#[CoversClass(Iso27001Mapping::class)]
#[CoversClass(EidasMapping::class)]
#[CoversClass(ControlCatalog::class)]
#[CoversClass(Control::class)]
final class FrameworkMappingTest extends TestCase
{
    // --- SOC 2 ---

    #[Test]
    public function soc2RegisterAddsExpectedControls(): void
    {
        $catalog = new ControlCatalog();

        Soc2Mapping::register($catalog);

        self::assertSame(42, $catalog->count());

        $controls = $catalog->byFramework('soc2');
        self::assertCount(42, $controls);

        $ids = array_map(static fn(Control $c): string => $c->id, $controls);
        self::assertContains('CC1.1', $ids);
        self::assertContains('CC6.1', $ids);
        self::assertContains('CC6.3', $ids);
        self::assertContains('CC7.2', $ids);
        self::assertContains('CC8.1', $ids);
    }

    #[Test]
    public function soc2ControlsHaveCorrectFramework(): void
    {
        $catalog = new ControlCatalog();

        Soc2Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertSame('soc2', $control->framework);
        }
    }

    #[Test]
    public function soc2ControlsHaveValidStatus(): void
    {
        $catalog = new ControlCatalog();

        Soc2Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertContains($control->status, [
                ControlStatus::Implemented,
                ControlStatus::Partial,
                ControlStatus::Planned,
            ]);
        }
    }

    #[Test]
    public function soc2ControlsHaveFrameworkFeatures(): void
    {
        $catalog = new ControlCatalog();

        Soc2Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty(
                $control->frameworkFeatures,
                sprintf('Control %s should list at least one framework feature.', $control->id),
            );
        }
    }

    // --- HIPAA ---

    #[Test]
    public function hipaaRegisterAddsExpectedControls(): void
    {
        $catalog = new ControlCatalog();

        HipaaMapping::register($catalog);

        self::assertSame(9, $catalog->count());

        $controls = $catalog->byFramework('hipaa');
        self::assertCount(9, $controls);

        $ids = array_map(static fn(Control $c): string => $c->id, $controls);
        self::assertContains('164.312(a)(1)', $ids);
        self::assertContains('164.312(a)(2)(iv)', $ids);
        self::assertContains('164.312(b)', $ids);
        self::assertContains('164.312(c)(1)', $ids);
        self::assertContains('164.312(e)(1)', $ids);
        self::assertContains('164.312(d)-2026', $ids);
        self::assertContains('164.312(a)(2)(iv)-2026', $ids);
        self::assertContains('164.308(a)(7)-2026', $ids);
        self::assertContains('164.312-2026-asset', $ids);
    }

    #[Test]
    public function hipaaControlsHaveCorrectFramework(): void
    {
        $catalog = new ControlCatalog();

        HipaaMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertSame('hipaa', $control->framework);
        }
    }

    #[Test]
    public function hipaaControlsHaveValidStatus(): void
    {
        $catalog = new ControlCatalog();

        HipaaMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertContains($control->status, [
                ControlStatus::Implemented,
                ControlStatus::Partial,
                ControlStatus::Planned,
            ]);
        }
    }

    #[Test]
    public function hipaaControlsHaveFrameworkFeatures(): void
    {
        $catalog = new ControlCatalog();

        HipaaMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty(
                $control->frameworkFeatures,
                sprintf('Control %s should list at least one framework feature.', $control->id),
            );
        }
    }

    // --- GDPR ---

    #[Test]
    public function gdprRegisterAddsExpectedControls(): void
    {
        $catalog = new ControlCatalog();

        GdprMapping::register($catalog);

        self::assertSame(5, $catalog->count());

        $controls = $catalog->byFramework('gdpr');
        self::assertCount(5, $controls);

        $ids = array_map(static fn(Control $c): string => $c->id, $controls);
        self::assertContains('Art5(1)(f)', $ids);
        self::assertContains('Art25', $ids);
        self::assertContains('Art30', $ids);
        self::assertContains('Art32', $ids);
        self::assertContains('Art33', $ids);
    }

    #[Test]
    public function gdprControlsHaveCorrectFramework(): void
    {
        $catalog = new ControlCatalog();

        GdprMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertSame('gdpr', $control->framework);
        }
    }

    #[Test]
    public function gdprControlsHaveValidStatus(): void
    {
        $catalog = new ControlCatalog();

        GdprMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertContains($control->status, [
                ControlStatus::Implemented,
                ControlStatus::Partial,
                ControlStatus::Planned,
            ]);
        }
    }

    #[Test]
    public function gdprControlsHaveFrameworkFeatures(): void
    {
        $catalog = new ControlCatalog();

        GdprMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty(
                $control->frameworkFeatures,
                sprintf('Control %s should list at least one framework feature.', $control->id),
            );
        }
    }

    // --- PCI-DSS ---

    #[Test]
    public function pciDssRegisterAddsExpectedControls(): void
    {
        $catalog = new ControlCatalog();

        PciDssMapping::register($catalog);

        self::assertSame(5, $catalog->count());

        $controls = $catalog->byFramework('pci_dss');
        self::assertCount(5, $controls);

        $ids = array_map(static fn(Control $c): string => $c->id, $controls);
        self::assertContains('Req2.3', $ids);
        self::assertContains('Req3.4', $ids);
        self::assertContains('Req6.5', $ids);
        self::assertContains('Req8.2', $ids);
        self::assertContains('Req10.2', $ids);
    }

    #[Test]
    public function pciDssControlsHaveCorrectFramework(): void
    {
        $catalog = new ControlCatalog();

        PciDssMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertSame('pci_dss', $control->framework);
        }
    }

    #[Test]
    public function pciDssControlsHaveValidStatus(): void
    {
        $catalog = new ControlCatalog();

        PciDssMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertContains($control->status, [
                ControlStatus::Implemented,
                ControlStatus::Partial,
                ControlStatus::Planned,
            ]);
        }
    }

    #[Test]
    public function pciDssControlsHaveFrameworkFeatures(): void
    {
        $catalog = new ControlCatalog();

        PciDssMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty(
                $control->frameworkFeatures,
                sprintf('Control %s should list at least one framework feature.', $control->id),
            );
        }
    }

    // --- NIS2 ---

    #[Test]
    public function nis2RegisterAddsExpectedControls(): void
    {
        $catalog = new ControlCatalog();

        Nis2Mapping::register($catalog);

        self::assertSame(8, $catalog->count());

        $controls = $catalog->byFramework('nis2');
        self::assertCount(8, $controls);

        $ids = array_map(static fn(Control $c): string => $c->id, $controls);
        self::assertContains('NIS2-Art21(a)', $ids);
        self::assertContains('NIS2-Art21(b)', $ids);
        self::assertContains('NIS2-Art21(d)', $ids);
        self::assertContains('NIS2-Art21(e)', $ids);
        self::assertContains('NIS2-Art21(g)', $ids);
        self::assertContains('NIS2-Art21(h)', $ids);
        self::assertContains('NIS2-Art21(i)', $ids);
        self::assertContains('NIS2-Art23', $ids);
    }

    #[Test]
    public function nis2ControlsHaveCorrectFramework(): void
    {
        $catalog = new ControlCatalog();

        Nis2Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertSame('nis2', $control->framework);
        }
    }

    #[Test]
    public function nis2ControlsHaveValidStatus(): void
    {
        $catalog = new ControlCatalog();

        Nis2Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertContains($control->status, [
                ControlStatus::Implemented,
                ControlStatus::Partial,
                ControlStatus::Planned,
            ]);
        }
    }

    #[Test]
    public function nis2ControlsHaveFrameworkFeatures(): void
    {
        $catalog = new ControlCatalog();

        Nis2Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty(
                $control->frameworkFeatures,
                sprintf('Control %s should list at least one framework feature.', $control->id),
            );
        }
    }

    // --- ISO 27001 ---

    #[Test]
    public function iso27001RegisterAddsExpectedControls(): void
    {
        $catalog = new ControlCatalog();

        Iso27001Mapping::register($catalog);

        self::assertSame(10, $catalog->count());

        $controls = $catalog->byFramework('iso27001');
        self::assertCount(10, $controls);

        $ids = array_map(static fn(Control $c): string => $c->id, $controls);
        self::assertContains('A.5.1', $ids);
        self::assertContains('A.8.1', $ids);
        self::assertContains('A.8.3', $ids);
        self::assertContains('A.8.5', $ids);
        self::assertContains('A.8.9', $ids);
        self::assertContains('A.8.12', $ids);
        self::assertContains('A.8.15', $ids);
        self::assertContains('A.8.24', $ids);
        self::assertContains('A.8.25', $ids);
        self::assertContains('A.8.26', $ids);
    }

    #[Test]
    public function iso27001ControlsHaveCorrectFramework(): void
    {
        $catalog = new ControlCatalog();

        Iso27001Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertSame('iso27001', $control->framework);
        }
    }

    #[Test]
    public function iso27001ControlsHaveValidStatus(): void
    {
        $catalog = new ControlCatalog();

        Iso27001Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertContains($control->status, [
                ControlStatus::Implemented,
                ControlStatus::Partial,
                ControlStatus::Planned,
            ]);
        }
    }

    #[Test]
    public function iso27001ControlsHaveFrameworkFeatures(): void
    {
        $catalog = new ControlCatalog();

        Iso27001Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty(
                $control->frameworkFeatures,
                sprintf('Control %s should list at least one framework feature.', $control->id),
            );
        }
    }

    // --- eIDAS ---

    #[Test]
    public function eidasRegisterAddsExpectedControls(): void
    {
        $catalog = new ControlCatalog();

        EidasMapping::register($catalog);

        self::assertSame(8, $catalog->count());

        $controls = $catalog->byFramework('eidas');
        self::assertCount(8, $controls);

        $ids = array_map(static fn(Control $c): string => $c->id, $controls);
        self::assertContains('eIDAS-Art8', $ids);
        self::assertContains('eIDAS-Art25-34', $ids);
        self::assertContains('eIDAS-Art35-40', $ids);
        self::assertContains('eIDAS-Art41-42', $ids);
        self::assertContains('eIDAS-Art43-44', $ids);
        self::assertContains('eIDAS-Art19', $ids);
        self::assertContains('eIDAS-Art24', $ids);
        self::assertContains('eIDAS-Art17', $ids);
    }

    #[Test]
    public function eidasControlsHaveCorrectFramework(): void
    {
        $catalog = new ControlCatalog();

        EidasMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertSame('eidas', $control->framework);
        }
    }

    #[Test]
    public function eidasControlsHaveValidStatus(): void
    {
        $catalog = new ControlCatalog();

        EidasMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertContains($control->status, [
                ControlStatus::Implemented,
                ControlStatus::Partial,
                ControlStatus::Planned,
            ]);
        }
    }

    #[Test]
    public function eidasControlsHaveFrameworkFeatures(): void
    {
        $catalog = new ControlCatalog();

        EidasMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty(
                $control->frameworkFeatures,
                sprintf('Control %s should list at least one framework feature.', $control->id),
            );
        }
    }

    // --- Cross-framework ---

    #[Test]
    public function allFrameworksCanBeRegisteredTogether(): void
    {
        $catalog = new ControlCatalog();

        Soc2Mapping::register($catalog);
        HipaaMapping::register($catalog);
        GdprMapping::register($catalog);
        PciDssMapping::register($catalog);
        Nis2Mapping::register($catalog);
        Iso27001Mapping::register($catalog);
        EidasMapping::register($catalog);

        self::assertSame(87, $catalog->count());
        self::assertCount(42, $catalog->byFramework('soc2'));
        self::assertCount(9, $catalog->byFramework('hipaa'));
        self::assertCount(5, $catalog->byFramework('gdpr'));
        self::assertCount(5, $catalog->byFramework('pci_dss'));
        self::assertCount(8, $catalog->byFramework('nis2'));
        self::assertCount(10, $catalog->byFramework('iso27001'));
        self::assertCount(8, $catalog->byFramework('eidas'));
    }

    #[Test]
    public function allControlsHaveNonEmptyTitleAndDescription(): void
    {
        $catalog = new ControlCatalog();

        Soc2Mapping::register($catalog);
        HipaaMapping::register($catalog);
        GdprMapping::register($catalog);
        PciDssMapping::register($catalog);
        Nis2Mapping::register($catalog);
        Iso27001Mapping::register($catalog);
        EidasMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty($control->title, sprintf('Control %s must have a title.', $control->id));
            self::assertNotEmpty($control->description, sprintf('Control %s must have a description.', $control->id));
        }
    }

    #[Test]
    public function allControlIdsAreUniqueAcrossFrameworks(): void
    {
        $catalog = new ControlCatalog();

        Soc2Mapping::register($catalog);
        HipaaMapping::register($catalog);
        GdprMapping::register($catalog);
        PciDssMapping::register($catalog);
        Nis2Mapping::register($catalog);
        Iso27001Mapping::register($catalog);
        EidasMapping::register($catalog);

        $ids = array_keys($catalog->all());

        self::assertSame($ids, array_unique($ids), 'Control IDs must be unique across all frameworks.');
    }
}
