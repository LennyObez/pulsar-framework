<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\CarouselBlock;

#[CoversClass(CarouselBlock::class)]
final class CarouselBlockTest extends TestCase
{
    private CarouselBlock $block;

    protected function setUp(): void
    {
        $this->block = new CarouselBlock();
    }

    #[Test]
    public function typeReturnsCarousel(): void
    {
        self::assertSame('carousel', $this->block->type());
    }

    #[Test]
    public function renderOutputsSlidesWithAriaAttributes(): void
    {
        $html = $this->block->render([
            'slides' => [
                ['imageUrl' => '/img/a.jpg', 'alt' => 'Image A'],
                ['imageUrl' => '/img/b.jpg', 'alt' => 'Image B'],
            ],
        ]);

        self::assertStringContainsString('aria-roledescription="carousel"', $html);
        self::assertStringContainsString('Slide 1 of 2', $html);
        self::assertStringContainsString('Slide 2 of 2', $html);
        self::assertStringContainsString('src="/img/a.jpg"', $html);
        self::assertStringContainsString('alt="Image A"', $html);
    }

    #[Test]
    public function renderIncludesNavigationDots(): void
    {
        $html = $this->block->render([
            'slides' => [
                ['imageUrl' => '/a.jpg', 'alt' => 'A'],
                ['imageUrl' => '/b.jpg', 'alt' => 'B'],
            ],
        ]);

        self::assertStringContainsString('Go to slide 1', $html);
        self::assertStringContainsString('Go to slide 2', $html);
    }

    #[Test]
    public function renderIncludesCaptionWhenPresent(): void
    {
        $html = $this->block->render([
            'slides' => [
                ['imageUrl' => '/a.jpg', 'alt' => 'A', 'caption' => 'First slide'],
            ],
        ]);

        self::assertStringContainsString('carousel__caption', $html);
        self::assertStringContainsString('First slide', $html);
    }

    #[Test]
    public function renderSetsAutoplayAndInterval(): void
    {
        $html = $this->block->render([
            'slides' => [['imageUrl' => '/a.jpg', 'alt' => 'A']],
            'autoplay' => true,
            'interval' => 3000,
        ]);

        self::assertStringContainsString('data-autoplay="true"', $html);
        self::assertStringContainsString('data-interval="3000"', $html);
    }

    #[Test]
    public function renderClampsNegativeIntervalToDefault(): void
    {
        $html = $this->block->render([
            'slides' => [['imageUrl' => '/a.jpg', 'alt' => 'A']],
            'interval' => -1,
        ]);

        self::assertStringContainsString('data-interval="5000"', $html);
    }

    #[Test]
    public function validateReturnsErrorWhenSlidesMissing(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('slides is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingSlideFields(): void
    {
        $errors = $this->block->validate([
            'slides' => [
                ['imageUrl' => 123],
            ],
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsErrorForNegativeInterval(): void
    {
        $errors = $this->block->validate([
            'slides' => [['imageUrl' => '/a.jpg', 'alt' => 'A']],
            'interval' => 0,
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('interval', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        $errors = $this->block->validate([
            'slides' => [
                ['imageUrl' => '/img.jpg', 'alt' => 'Image'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
