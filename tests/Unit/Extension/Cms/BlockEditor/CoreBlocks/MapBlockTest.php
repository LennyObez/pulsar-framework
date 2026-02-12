<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

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
    public function rendersMapWithRequiredFieldsOnly(): void
    {
        $html = $this->block->render([
            'latitude' => 48.8566,
            'longitude' => 2.3522,
        ]);

        self::assertStringContainsString('class="map"', $html);
        self::assertStringContainsString('<iframe', $html);
        self::assertStringContainsString('openstreetmap.org/export/embed.html', $html);
        self::assertStringContainsString('48.856600', $html);
        self::assertStringContainsString('2.352200', $html);
        self::assertStringContainsString('</iframe>', $html);
        self::assertStringContainsString('</figure>', $html);
    }

    #[Test]
    public function rendersWithOptionalCaption(): void
    {
        $html = $this->block->render([
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'caption' => 'Paris, France',
        ]);

        self::assertStringContainsString('<figcaption>Paris, France</figcaption>', $html);
    }

    #[Test]
    public function omitsFigcaptionWhenNoCaptionProvided(): void
    {
        $html = $this->block->render([
            'latitude' => 48.8566,
            'longitude' => 2.3522,
        ]);

        self::assertStringNotContainsString('<figcaption>', $html);
    }

    #[Test]
    public function iframeSandboxAttribute(): void
    {
        $html = $this->block->render([
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        self::assertStringContainsString('sandbox="allow-scripts"', $html);
    }

    #[Test]
    public function iframeHasLazyLoadingAndTitle(): void
    {
        $html = $this->block->render([
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('title="Map"', $html);
    }

    #[Test]
    public function acceptsIntegerCoordinates(): void
    {
        $html = $this->block->render([
            'latitude' => 48,
            'longitude' => 2,
        ]);

        self::assertStringContainsString('48.000000', $html);
        self::assertStringContainsString('2.000000', $html);
    }

    #[Test]
    public function escapesXssInCaption(): void
    {
        $html = $this->block->render([
            'latitude' => 0.0,
            'longitude' => 0.0,
            'caption' => '<script>alert("xss")</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function validatesLatitudeRequired(): void
    {
        $errors = $this->block->validate(['longitude' => 2.3522]);

        self::assertContains('latitude is required', $errors);
    }

    #[Test]
    public function validatesLongitudeRequired(): void
    {
        $errors = $this->block->validate(['latitude' => 48.8566]);

        self::assertContains('longitude is required', $errors);
    }

    #[Test]
    public function validatesLatitudeMustBeNumber(): void
    {
        $errors = $this->block->validate([
            'latitude' => 'north',
            'longitude' => 2.3522,
        ]);

        self::assertContains('latitude must be a number', $errors);
    }

    #[Test]
    public function validatesLatitudeRange(): void
    {
        $errors = $this->block->validate([
            'latitude' => -91.0,
            'longitude' => 0.0,
        ]);

        self::assertContains('latitude must be between -90 and 90', $errors);
    }

    #[Test]
    public function validatesLatitudeUpperBound(): void
    {
        $errors = $this->block->validate([
            'latitude' => 91.0,
            'longitude' => 0.0,
        ]);

        self::assertContains('latitude must be between -90 and 90', $errors);
    }

    #[Test]
    public function validatesLongitudeRange(): void
    {
        $errors = $this->block->validate([
            'latitude' => 0.0,
            'longitude' => -181.0,
        ]);

        self::assertContains('longitude must be between -180 and 180', $errors);
    }

    #[Test]
    public function validatesLongitudeUpperBound(): void
    {
        $errors = $this->block->validate([
            'latitude' => 0.0,
            'longitude' => 181.0,
        ]);

        self::assertContains('longitude must be between -180 and 180', $errors);
    }

    #[Test]
    public function validatesZoomRange(): void
    {
        $errors = $this->block->validate([
            'latitude' => 0.0,
            'longitude' => 0.0,
            'zoom' => 0,
        ]);

        self::assertContains('zoom must be an integer between 1 and 20', $errors);
    }

    #[Test]
    public function validatesZoomUpperBound(): void
    {
        $errors = $this->block->validate([
            'latitude' => 0.0,
            'longitude' => 0.0,
            'zoom' => 21,
        ]);

        self::assertContains('zoom must be an integer between 1 and 20', $errors);
    }

    #[Test]
    public function validatesIntegerCoordinatesAccepted(): void
    {
        $errors = $this->block->validate([
            'latitude' => 48,
            'longitude' => 2,
        ]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'zoom' => 15,
        ]);

        self::assertSame([], $errors);
    }
}
