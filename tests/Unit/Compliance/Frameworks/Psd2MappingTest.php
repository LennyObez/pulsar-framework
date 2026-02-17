<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\Psd2Mapping;

#[CoversClass(Psd2Mapping::class)]
final class Psd2MappingTest extends TestCase
{
    #[Test]
    public function registerAddsExpectedControls(): void
    {
        $catalog = new ControlCatalog();

        Psd2Mapping::register($catalog);

        self::assertSame(7, $catalog->count());

        $controls = $catalog->byFramework('psd2');
        self::assertCount(7, $controls);

        $ids = array_map(static fn(Control $c): string => $c->id, $controls);
        self::assertContains('Art97.1', $ids);
        self::assertContains('Art97.2', $ids);
        self::assertContains('RTS.Art16', $ids);
        self::assertContains('RTS.Art18', $ids);
        self::assertContains('Art66', $ids);
        self::assertContains('Art67', $ids);
        self::assertContains('Art94', $ids);
    }

    #[Test]
    public function controlsHaveCorrectFramework(): void
    {
        $catalog = new ControlCatalog();

        Psd2Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertSame('psd2', $control->framework);
        }
    }

    #[Test]
    public function controlsHaveValidStatus(): void
    {
        $catalog = new ControlCatalog();

        Psd2Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertContains($control->status, [
                ControlStatus::Implemented,
                ControlStatus::Partial,
                ControlStatus::Planned,
            ]);
        }
    }

    #[Test]
    public function controlsHaveFrameworkFeatures(): void
    {
        $catalog = new ControlCatalog();

        Psd2Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty(
                $control->frameworkFeatures,
                "Control {$control->id} should list at least one framework feature.",
            );
        }
    }

    #[Test]
    public function scaControlsCoverDynamicLinking(): void
    {
        $catalog = new ControlCatalog();

        Psd2Mapping::register($catalog);

        $allFeatures = [];
        foreach ($catalog->all() as $control) {
            $allFeatures = array_merge($allFeatures, $control->frameworkFeatures);
        }
        $uniqueFeatures = array_unique($allFeatures);

        self::assertContains('sca_enforcement', $uniqueFeatures);
        self::assertContains('sca_dynamic_linking', $uniqueFeatures);
    }
}
