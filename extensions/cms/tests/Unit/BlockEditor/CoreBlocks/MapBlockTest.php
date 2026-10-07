<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\MapBlock;

#[CoversClass(MapBlock::class)]
final class MapBlockTest extends TestCase
{
    private MapBlock $block;

    protected function setUp(): void
    {
        $this->block = new MapBlock();
    }

    #[Test]
    public function typeReturnsMap(): void
    {
        self::assertSame('map', $this->block->type());
    }

    #[Test]
    public function renderOutputsIframeWithCoordinates(): void
    {
        $html = $this->block->render([
            'latitude' => 52.3676,
            'longitude' => 4.9041,
        ]);

        self::assertStringContainsString('openstreetmap.org', $html);
        self::assertStringContainsString('52.367600', $html);
        self::assertStringContainsString('4.904100', $html);
        self::assertStringContainsString('sandbox="allow-scripts"', $html);
    }

    #[Test]
    public function renderUsesDefaultZoom13(): void
    {
        $html = $this->block->render([
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        self::assertStringContainsString('zoom=13', $html);
    }

    #[Test]
    public function renderIncludesCaption(): void
    {
        $html = $this->block->render([
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'caption' => 'Paris, France',
        ]);

        self::assertStringContainsString('<figcaption>Paris, France</figcaption>', $html);
    }

    #[Test]
    public function renderClampsInvalidZoom(): void
    {
        $html = $this->block->render([
            'latitude' => 0.0,
            'longitude' => 0.0,
            'zoom' => 25,
        ]);

        self::assertStringContainsString('zoom=13', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingLatitude(): void
    {
        $errors = $this->block->validate(['longitude' => 0.0]);

        self::assertStringContainsString('latitude is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForOutOfRangeLatitude(): void
    {
        $errors = $this->block->validate([
            'latitude' => 91.0,
            'longitude' => 0.0,
        ]);

        self::assertStringContainsString('between -90 and 90', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForOutOfRangeLongitude(): void
    {
        $errors = $this->block->validate([
            'latitude' => 0.0,
            'longitude' => 181.0,
        ]);

        self::assertStringContainsString('between -180 and 180', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForInvalidZoom(): void
    {
        $errors = $this->block->validate([
            'latitude' => 0.0,
            'longitude' => 0.0,
            'zoom' => 0,
        ]);

        self::assertStringContainsString('zoom', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'latitude' => 52.3676,
            'longitude' => 4.9041,
            'zoom' => 15,
        ]));
    }
}
