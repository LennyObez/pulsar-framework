<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Compliance\Frameworks\CcpaMapping;

use function array_map;
use function sprintf;

#[CoversClass(CcpaMapping::class)]
final class CcpaMappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        CcpaMapping::register($this->catalog);
    }

    #[Test]
    public function registersSixControls(): void
    {
        $controls = $this->catalog->byFramework('ccpa');

        self::assertCount(6, $controls);
    }

    #[Test]
    public function registersExpectedControlIds(): void
    {
        $controls = $this->catalog->byFramework('ccpa');
        $ids = array_map(static fn(Control $c): string => $c->id, $controls);

        self::assertContains('CCPA-1798.100', $ids);
        self::assertContains('CCPA-1798.105', $ids);
        self::assertContains('CCPA-1798.120', $ids);
        self::assertContains('CCPA-1798.140', $ids);
        self::assertContains('CCPA-1798.150', $ids);
        self::assertContains('CCPA-1798.185', $ids);
    }

    #[Test]
    public function allControlsHaveCorrectFramework(): void
    {
        foreach ($this->catalog->all() as $control) {
            self::assertSame('ccpa', $control->framework);
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
    public function rightToKnowControlIsImplemented(): void
    {
        $control = $this->catalog->get('CCPA-1798.100');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertStringContainsString('Right to Know', $control->title);
    }

    #[Test]
    public function rightToDeleteControlIsImplemented(): void
    {
        $control = $this->catalog->get('CCPA-1798.105');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function rightToOptOutControlIsImplemented(): void
    {
        $control = $this->catalog->get('CCPA-1798.120');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function dataCategoriesControlReferencesEncryption(): void
    {
        $control = $this->catalog->get('CCPA-1798.140');

        self::assertNotNull($control);
        self::assertContains('crypto_keyring', $control->frameworkFeatures);
    }

    #[Test]
    public function safeHarborControlReferencesTokenization(): void
    {
        $control = $this->catalog->get('CCPA-1798.150');

        self::assertNotNull($control);
        self::assertContains('tokenization', $control->frameworkFeatures);
    }
}
