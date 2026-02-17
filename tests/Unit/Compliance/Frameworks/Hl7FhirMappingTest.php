<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\Hl7FhirMapping;

#[CoversClass(Hl7FhirMapping::class)]
final class Hl7FhirMappingTest extends TestCase
{
    #[Test]
    public function registerAddsExpectedControls(): void
    {
        $catalog = new ControlCatalog();

        Hl7FhirMapping::register($catalog);

        self::assertSame(10, $catalog->count());

        $controls = $catalog->byFramework('hl7_fhir');
        self::assertCount(10, $controls);

        $ids = array_map(static fn(Control $c): string => $c->id, $controls);
        self::assertContains('FHIR-RES-001', $ids);
        self::assertContains('FHIR-REST-001', $ids);
        self::assertContains('FHIR-BUNDLE-001', $ids);
        self::assertContains('FHIR-SEARCH-001', $ids);
        self::assertContains('FHIR-TERM-001', $ids);
        self::assertContains('FHIR-SMART-001', $ids);
        self::assertContains('FHIR-AUDIT-001', $ids);
        self::assertContains('FHIR-VAL-001', $ids);
        self::assertContains('FHIR-SEC-001', $ids);
        self::assertContains('FHIR-PROF-001', $ids);
    }

    #[Test]
    public function controlsHaveCorrectFramework(): void
    {
        $catalog = new ControlCatalog();

        Hl7FhirMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertSame('hl7_fhir', $control->framework);
        }
    }

    #[Test]
    public function controlsHaveValidStatus(): void
    {
        $catalog = new ControlCatalog();

        Hl7FhirMapping::register($catalog);

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

        Hl7FhirMapping::register($catalog);

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

        Hl7FhirMapping::register($catalog);

        foreach ($catalog->all() as $control) {
            self::assertNotEmpty($control->title, "Control {$control->id} must have a title.");
            self::assertNotEmpty($control->description, "Control {$control->id} must have a description.");
        }
    }

    #[Test]
    public function coversSmartOnFhirAndTerminology(): void
    {
        $catalog = new ControlCatalog();

        Hl7FhirMapping::register($catalog);

        $allFeatures = [];
        foreach ($catalog->all() as $control) {
            $allFeatures = array_merge($allFeatures, $control->frameworkFeatures);
        }
        $uniqueFeatures = array_unique($allFeatures);

        self::assertContains('fhir_smart_launch', $uniqueFeatures);
        self::assertContains('fhir_terminology', $uniqueFeatures);
    }
}
