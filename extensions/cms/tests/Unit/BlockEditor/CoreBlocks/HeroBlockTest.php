<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

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
    public function renderOutputsSectionWithTitle(): void
    {
        $html = $this->block->render(['title' => 'Welcome']);

        self::assertStringContainsString('<section class="hero', $html);
        self::assertStringContainsString('<h1>Welcome</h1>', $html);
    }

    #[Test]
    public function renderDefaultsToCenterAlignment(): void
    {
        $html = $this->block->render(['title' => 'Test']);

        self::assertStringContainsString('hero--center', $html);
    }

    #[Test]
    public function renderUsesValidAlignment(): void
    {
        $html = $this->block->render([
            'title' => 'Test',
            'alignment' => 'left',
        ]);

        self::assertStringContainsString('hero--left', $html);
    }

    #[Test]
    public function renderIncludesSubtitle(): void
    {
        $html = $this->block->render([
            'title' => 'Welcome',
            'subtitle' => 'Start your journey',
        ]);

        self::assertStringContainsString('hero__subtitle', $html);
        self::assertStringContainsString('Start your journey', $html);
    }

    #[Test]
    public function renderIncludesCtaButton(): void
    {
        $html = $this->block->render([
            'title' => 'Welcome',
            'ctaText' => 'Get Started',
            'ctaUrl' => '/signup',
        ]);

        self::assertStringContainsString('hero__cta', $html);
        self::assertStringContainsString('Get Started', $html);
        self::assertStringContainsString('href="/signup"', $html);
    }

    #[Test]
    public function renderIncludesBackgroundImage(): void
    {
        $html = $this->block->render([
            'title' => 'Test',
            'backgroundImage' => '/img/hero.jpg',
        ]);

        self::assertStringContainsString('background-image:url(/img/hero.jpg)', $html);
    }

    #[Test]
    public function renderOmitsCtaWhenOnlyTextProvided(): void
    {
        $html = $this->block->render([
            'title' => 'Test',
            'ctaText' => 'Click',
        ]);

        self::assertStringNotContainsString('hero__cta', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingTitle(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('title is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForInvalidAlignment(): void
    {
        $errors = $this->block->validate([
            'title' => 'Test',
            'alignment' => 'stretch',
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate(['title' => 'Welcome']));
    }
}
