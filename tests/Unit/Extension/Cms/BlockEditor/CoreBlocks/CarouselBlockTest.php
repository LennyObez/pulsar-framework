<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

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
    public function rendersCarouselWithRequiredFieldsOnly(): void
    {
        $html = $this->block->render([
            'slides' => [
                ['imageUrl' => '/photo1.jpg', 'alt' => 'Photo 1'],
                ['imageUrl' => '/photo2.jpg', 'alt' => 'Photo 2'],
            ],
        ]);

        self::assertStringContainsString('class="carousel"', $html);
        self::assertStringContainsString('class="carousel__slides"', $html);
        self::assertStringContainsString('<img src="/photo1.jpg" alt="Photo 1">', $html);
        self::assertStringContainsString('<img src="/photo2.jpg" alt="Photo 2">', $html);
        self::assertStringContainsString('data-autoplay="false"', $html);
        self::assertStringContainsString('data-interval="5000"', $html);
    }

    #[Test]
    public function rendersWithOptionalCaption(): void
    {
        $html = $this->block->render([
            'slides' => [
                ['imageUrl' => '/photo.jpg', 'alt' => 'Photo', 'caption' => 'A great photo'],
            ],
        ]);

        self::assertStringContainsString('<p class="carousel__caption">A great photo</p>', $html);
    }

    #[Test]
    public function rendersWithAutoplayAndInterval(): void
    {
        $html = $this->block->render([
            'slides' => [
                ['imageUrl' => '/photo.jpg', 'alt' => 'Photo'],
            ],
            'autoplay' => true,
            'interval' => 3000,
        ]);

        self::assertStringContainsString('data-autoplay="true"', $html);
        self::assertStringContainsString('data-interval="3000"', $html);
    }

    #[Test]
    public function navDotsCountMatchesSlides(): void
    {
        $html = $this->block->render([
            'slides' => [
                ['imageUrl' => '/a.jpg', 'alt' => 'A'],
                ['imageUrl' => '/b.jpg', 'alt' => 'B'],
                ['imageUrl' => '/c.jpg', 'alt' => 'C'],
            ],
        ]);

        self::assertStringContainsString('aria-label="Slide 1"', $html);
        self::assertStringContainsString('aria-label="Slide 2"', $html);
        self::assertStringContainsString('aria-label="Slide 3"', $html);
        self::assertSame(3, substr_count($html, 'class="carousel__dot"'));
    }

    #[Test]
    public function escapesXssInImageUrlAltAndCaption(): void
    {
        $html = $this->block->render([
            'slides' => [
                [
                    'imageUrl' => '"><script>xss</script>',
                    'alt' => '<img onerror="hack">',
                    'caption' => '<script>alert(1)</script>',
                ],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('onerror="hack"', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('onerror=&quot;hack&quot;', $html);
    }

    #[Test]
    public function validatesRequiredSlides(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('slides is required and must be an array', $errors);
    }

    #[Test]
    public function validatesSlidesMustBeArray(): void
    {
        $errors = $this->block->validate(['slides' => 'not-an-array']);

        self::assertContains('slides is required and must be an array', $errors);
    }

    #[Test]
    public function validatesSlideImageUrlRequired(): void
    {
        $errors = $this->block->validate([
            'slides' => [['alt' => 'Photo']],
        ]);

        self::assertContains('slides[0].imageUrl is required and must be a string', $errors);
    }

    #[Test]
    public function validatesSlideAltRequired(): void
    {
        $errors = $this->block->validate([
            'slides' => [['imageUrl' => '/photo.jpg']],
        ]);

        self::assertContains('slides[0].alt is required and must be a string', $errors);
    }

    #[Test]
    public function validatesIntervalPositiveInteger(): void
    {
        $errors = $this->block->validate([
            'slides' => [['imageUrl' => '/photo.jpg', 'alt' => 'Photo']],
            'interval' => 0,
        ]);

        self::assertContains('interval must be a positive integer', $errors);
    }

    #[Test]
    public function validatesIntervalMustBeInteger(): void
    {
        $errors = $this->block->validate([
            'slides' => [['imageUrl' => '/photo.jpg', 'alt' => 'Photo']],
            'interval' => 'fast',
        ]);

        self::assertContains('interval must be a positive integer', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'slides' => [
                ['imageUrl' => '/photo.jpg', 'alt' => 'Photo'],
            ],
            'autoplay' => true,
            'interval' => 3000,
        ]);

        self::assertSame([], $errors);
    }
}
