<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\Iso42001Mapping;

#[CoversClass(Iso42001Mapping::class)]
final class Iso42001MappingTest extends TestCase
{
    #[Test]
    public function registerAddsExpectedControls(): void
    {
        $catalog = new ControlCatalog();

        Iso42001Mapping::register($catalog);

        self::assertSame(15, $catalog->count());

        $controls = $catalog->byFramework('iso42001');
        self::assertCount(15, $controls);

        $ids = array_map(static fn(Control $c): string => $c->id, $controls);
        self::assertContains('ISO42001-6.1.2', $ids);
        self::assertContains('ISO42001-8.4', $ids);
        self::assertContains('ISO42001-9.1', $ids);
        self::assertContains('ISO42001-A.8', $ids);
        self::assertContains('ISO42001-A.10', $ids);
    }

    #[Test]
    public function controlsHaveCorrectFramework(): void
    {
        $catalog = new ControlCatalog();

        Iso42001Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertSame('iso42001', $control->framework);
        }
    }

    #[Test]
    public function controlsHaveValidStatus(): void
    {
        $catalog = new ControlCatalog();

        Iso42001Mapping::register($catalog);

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

        Iso42001Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty(
                $control->frameworkFeatures,
                "Control {$control->id} should list at least one framework feature.",
            );
        }
    }

    #[Test]
    public function controlsHaveNonEmptyTitleAndDescription(): void
    {
        $catalog = new ControlCatalog();

        Iso42001Mapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty($control->title, "Control {$control->id} must have a title.");
            self::assertNotEmpty($control->description, "Control {$control->id} must have a description.");
        }
    }

    #[Test]
    public function coversAllAiGovernanceLifecycleStages(): void
    {
        $catalog = new ControlCatalog();

        Iso42001Mapping::register($catalog);

        $allFeatures = [];
        foreach ($catalog->all() as $control) {
            $allFeatures = array_merge($allFeatures, $control->frameworkFeatures);
        }
        $uniqueFeatures = array_unique($allFeatures);

        self::assertContains('ai_lifecycle_manager', $uniqueFeatures);
        self::assertContains('model_cards', $uniqueFeatures);
        self::assertContains('ai_audit_logging', $uniqueFeatures);
        self::assertContains('explainability', $uniqueFeatures);
        self::assertContains('data_provenance', $uniqueFeatures);
        self::assertContains('deployment_gates', $uniqueFeatures);
    }
}
