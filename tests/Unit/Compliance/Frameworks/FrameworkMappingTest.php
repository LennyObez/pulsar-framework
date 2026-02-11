<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\GdprMapping;
use Pulsar\Compliance\Frameworks\HipaaMapping;
use Pulsar\Compliance\Frameworks\PciDssMapping;
use Pulsar\Compliance\Frameworks\Soc2Mapping;

use function sprintf;

#[CoversClass(Soc2Mapping::class)]
#[CoversClass(HipaaMapping::class)]
#[CoversClass(GdprMapping::class)]
#[CoversClass(PciDssMapping::class)]
#[CoversClass(ControlCatalog::class)]
#[CoversClass(Control::class)]
#[CoversClass(ControlStatus::class)]
final class FrameworkMappingTest extends TestCase
{
    // --- SOC 2 ---

    #[Test]
    public function soc2RegisterAddsExpectedControls(): void
    {
        $catalog = new ControlCatalog();

        Soc2Mapping::register($catalog);

        self::assertSame(5, $catalog->count());

        $controls = $catalog->byFramework('soc2');
        self::assertCount(5, $controls);

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

        self::assertSame(5, $catalog->count());

        $controls = $catalog->byFramework('hipaa');
        self::assertCount(5, $controls);

        $ids = array_map(static fn(Control $c): string => $c->id, $controls);
        self::assertContains('164.312(a)(1)', $ids);
        self::assertContains('164.312(a)(2)(iv)', $ids);
        self::assertContains('164.312(b)', $ids);
        self::assertContains('164.312(c)(1)', $ids);
        self::assertContains('164.312(e)(1)', $ids);
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

    // --- Cross-framework ---

    #[Test]
    public function allFrameworksCanBeRegisteredTogether(): void
    {
        $catalog = new ControlCatalog();

        Soc2Mapping::register($catalog);
        HipaaMapping::register($catalog);
        GdprMapping::register($catalog);
        PciDssMapping::register($catalog);

        self::assertSame(20, $catalog->count());
        self::assertCount(5, $catalog->byFramework('soc2'));
        self::assertCount(5, $catalog->byFramework('hipaa'));
        self::assertCount(5, $catalog->byFramework('gdpr'));
        self::assertCount(5, $catalog->byFramework('pci_dss'));
    }

    #[Test]
    public function allControlsHaveNonEmptyTitleAndDescription(): void
    {
        $catalog = new ControlCatalog();

        Soc2Mapping::register($catalog);
        HipaaMapping::register($catalog);
        GdprMapping::register($catalog);
        PciDssMapping::register($catalog);

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

        $ids = array_keys($catalog->all());

        self::assertSame($ids, array_unique($ids), 'Control IDs must be unique across all frameworks.');
    }
}
