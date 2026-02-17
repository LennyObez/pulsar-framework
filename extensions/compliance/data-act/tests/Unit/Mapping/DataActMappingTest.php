<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Extension\DataAct\Mapping\DataActMapping;

#[CoversClass(DataActMapping::class)]
final class DataActMappingTest extends TestCase
{
    #[Test]
    public function registersDataActControls(): void
    {
        $catalog = new ControlCatalog();

        DataActMapping::register($catalog);

        $controls = $catalog->all();
        self::assertNotEmpty($controls);

        $controlIds = array_map(static fn($c) => $c->id, $controls);
        self::assertContains('data-act-art-4', $controlIds);
        self::assertContains('data-act-art-5', $controlIds);
        self::assertContains('data-act-art-6', $controlIds);
        self::assertContains('data-act-art-25', $controlIds);
        self::assertContains('data-act-art-28', $controlIds);
        self::assertContains('data-act-art-31', $controlIds);
    }

    #[Test]
    public function allControlsHaveDataActFramework(): void
    {
        $catalog = new ControlCatalog();

        DataActMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertSame('data_act', $control->framework);
        }
    }

    #[Test]
    public function allControlsHaveNonEmptyTitleAndDescription(): void
    {
        $catalog = new ControlCatalog();

        DataActMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty($control->title, "Control {$control->id} has empty title");
            self::assertNotEmpty($control->description, "Control {$control->id} has empty description");
        }
    }
}
