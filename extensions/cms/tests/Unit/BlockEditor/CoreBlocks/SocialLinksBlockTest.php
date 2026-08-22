<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\SocialLinksBlock;

#[CoversClass(SocialLinksBlock::class)]
final class SocialLinksBlockTest extends TestCase
{
    private SocialLinksBlock $block;

    protected function setUp(): void
    {
        $this->block = new SocialLinksBlock();
    }

    #[Test]
    public function typeReturnsSocialLinks(): void
    {
        self::assertSame('social-links', $this->block->type());
    }

    #[Test]
    public function renderOutputsNavWithLinks(): void
    {
        $html = $this->block->render([
            'links' => [
                ['platform' => 'github', 'url' => 'https://github.com/example'],
            ],
        ]);

        self::assertStringContainsString('<nav class="social-links', $html);
        self::assertStringContainsString('aria-label="Social media links"', $html);
        self::assertStringContainsString('GitHub</a>', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    #[Test]
    public function renderUsesKnownPlatformLabels(): void
    {
        $html = $this->block->render([
            'links' => [
                ['platform' => 'twitter', 'url' => 'https://twitter.com/test'],
                ['platform' => 'linkedin', 'url' => 'https://linkedin.com/in/test'],
            ],
        ]);

        self::assertStringContainsString('Twitter</a>', $html);
        self::assertStringContainsString('LinkedIn</a>', $html);
    }

    #[Test]
    public function renderFallsBackToPlatformNameForUnknown(): void
    {
        $html = $this->block->render([
            'links' => [
                ['platform' => 'custom-platform', 'url' => 'https://custom.com'],
            ],
        ]);

        self::assertStringContainsString('custom-platform</a>', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingLinks(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('links is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForUnknownPlatform(): void
    {
        $errors = $this->block->validate([
            'links' => [
                ['platform' => 'myspace', 'url' => 'https://myspace.com'],
            ],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('not a known platform', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForInvalidStyle(): void
    {
        $errors = $this->block->validate([
            'links' => [['platform' => 'github', 'url' => 'https://github.com']],
            'style' => 'sparkle',
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsErrorForInvalidSize(): void
    {
        $errors = $this->block->validate([
            'links' => [['platform' => 'github', 'url' => 'https://github.com']],
            'size' => 'xxl',
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'links' => [
                ['platform' => 'github', 'url' => 'https://github.com'],
                ['platform' => 'twitter', 'url' => 'https://twitter.com'],
            ],
            'style' => 'both',
            'size' => 'md',
        ]));
    }
}
