<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\HeroBlock;

#[CoversClass(HeroBlock::class)]
final class HeroBlockTest extends TestCase
{
    private HeroBlock $block;

    protected function setUp(): void
    {
        $this->block = new HeroBlock();
    }

    #[Test]
    public function typeReturnsHero(): void
    {
        self::assertSame('hero', $this->block->type());
    }

    #[Test]
    public function rendersWithRequiredFieldsOnly(): void
    {
        $html = $this->block->render(['title' => 'Welcome']);

        self::assertStringContainsString('<section class="hero hero--center"', $html);
        self::assertStringContainsString('<h1>Welcome</h1>', $html);
        self::assertStringNotContainsString('hero__subtitle', $html);
        self::assertStringNotContainsString('hero__cta', $html);
        self::assertStringNotContainsString('background-image', $html);
    }

    #[Test]
    public function rendersWithAllOptionalFields(): void
    {
        $html = $this->block->render([
            'title' => 'Welcome',
            'subtitle' => 'A tagline',
            'backgroundImage' => '/hero.jpg',
            'ctaText' => 'Learn More',
            'ctaUrl' => 'https://example.com',
            'alignment' => 'left',
        ]);

        self::assertStringContainsString('hero--left', $html);
        self::assertStringContainsString('background-image:url(/hero.jpg)', $html);
        self::assertStringContainsString('<p class="hero__subtitle">A tagline</p>', $html);
        self::assertStringContainsString('<a href="https://example.com" class="hero__cta">Learn More</a>', $html);
    }

    #[Test]
    public function escapesXssInTitle(): void
    {
        $html = $this->block->render(['title' => '<script>xss</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function escapesXssInSubtitle(): void
    {
        $html = $this->block->render([
            'title' => 'Safe',
            'subtitle' => '<img onerror="xss">',
        ]);

        self::assertStringNotContainsString('<img onerror', $html);
    }

    #[Test]
    public function validatesRequiredTitle(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('title is required and must be a string', $errors);
    }

    #[Test]
    public function validatesInvalidAlignment(): void
    {
        $errors = $this->block->validate([
            'title' => 'Test',
            'alignment' => 'top',
        ]);

        self::assertContains('alignment must be one of: left, center, right', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'title' => 'Welcome',
            'subtitle' => 'Hello',
            'alignment' => 'right',
        ]);

        self::assertSame([], $errors);
    }
}
