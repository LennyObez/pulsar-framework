<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;
use Pulsar\Extension\Dsa\Mapping\DsaMapping;

use function count;

#[CoversClass(DsaMapping::class)]
final class DsaMappingTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
        DsaMapping::register($this->catalog);
    }

    #[Test]
    public function registerAddsAllDsaControls(): void
    {
        $dsaControls = $this->catalog->byFramework('dsa');

        self::assertGreaterThanOrEqual(10, count($dsaControls));
    }

    #[Test]
    public function contactPointControlIsRegistered(): void
    {
        $control = $this->catalog->get('dsa-art-11');

        self::assertNotNull($control);
        self::assertSame('dsa', $control->framework);
        self::assertSame('Points of contact for authorities', $control->title);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function legalRepresentativeControlIsRegistered(): void
    {
        $control = $this->catalog->get('dsa-art-13');

        self::assertNotNull($control);
        self::assertSame('dsa', $control->framework);
        self::assertSame(ControlStatus::Implemented, $control->status);
    }

    #[Test]
    public function transparencyReportingControlIsRegistered(): void
    {
        $control = $this->catalog->get('dsa-art-15');

        self::assertNotNull($control);
        self::assertSame('Transparency reporting obligations', $control->title);
        self::assertContains('transparency_report', $control->frameworkFeatures);
        self::assertContains('moderation_log', $control->frameworkFeatures);
    }

    #[Test]
    public function noticeAndActionControlIsRegistered(): void
    {
        $control = $this->catalog->get('dsa-art-16');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('notice_action', $control->frameworkFeatures);
    }

    #[Test]
    public function appealHandlingControlIsRegistered(): void
    {
        $control = $this->catalog->get('dsa-art-20');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Implemented, $control->status);
        self::assertContains('appeal_handler', $control->frameworkFeatures);
    }

    #[Test]
    public function trustedFlaggerControlIsRegistered(): void
    {
        $control = $this->catalog->get('dsa-art-22');

        self::assertNotNull($control);
        self::assertContains('trusted_flagger_registry', $control->frameworkFeatures);
    }

    #[Test]
    public function vlopSystemicRiskControlIsPartial(): void
    {
        $control = $this->catalog->get('dsa-art-34');

        self::assertNotNull($control);
        self::assertSame(ControlStatus::Partial, $control->status);
    }

    #[Test]
    public function allControlsBelongToDsaFramework(): void
    {
        $dsaControls = $this->catalog->byFramework('dsa');

        foreach ($dsaControls as $control) {
            self::assertSame('dsa', $control->framework);
        }
    }
}
