<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

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
    public function rendersBasicSocialLinks(): void
    {
        $html = $this->block->render([
            'links' => [
                ['platform' => 'twitter', 'url' => 'https://twitter.com/example'],
                ['platform' => 'github', 'url' => 'https://github.com/example'],
            ],
        ]);

        self::assertStringContainsString('<nav class="social-links social-links--both social-links--md"', $html);
        self::assertStringContainsString('aria-label="Social media links"', $html);
        self::assertStringContainsString('href="https://twitter.com/example"', $html);
        self::assertStringContainsString('href="https://github.com/example"', $html);
    }

    #[Test]
    public function rendersPlatformLabels(): void
    {
        $html = $this->block->render([
            'links' => [
                ['platform' => 'linkedin', 'url' => 'https://linkedin.com/in/test'],
                ['platform' => 'youtube', 'url' => 'https://youtube.com/@test'],
                ['platform' => 'tiktok', 'url' => 'https://tiktok.com/@test'],
            ],
        ]);

        self::assertStringContainsString('>LinkedIn</a>', $html);
        self::assertStringContainsString('>YouTube</a>', $html);
        self::assertStringContainsString('>TikTok</a>', $html);
    }

    #[Test]
    public function rendersWithStyleAndSize(): void
    {
        $html = $this->block->render([
            'links' => [
                ['platform' => 'facebook', 'url' => 'https://facebook.com/example'],
            ],
            'style' => 'icons',
            'size' => 'lg',
        ]);

        self::assertStringContainsString('social-links--icons', $html);
        self::assertStringContainsString('social-links--lg', $html);
    }

    #[Test]
    public function rendersPlatformCssClass(): void
    {
        $html = $this->block->render([
            'links' => [
                ['platform' => 'instagram', 'url' => 'https://instagram.com/example'],
            ],
        ]);

        self::assertStringContainsString('class="social-link social-link--instagram"', $html);
    }

    #[Test]
    public function rendersRelAndTargetAttributes(): void
    {
        $html = $this->block->render([
            'links' => [
                ['platform' => 'mastodon', 'url' => 'https://mastodon.social/@test'],
            ],
        ]);

        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringContainsString('target="_blank"', $html);
    }

    #[Test]
    public function escapesXssInUrl(): void
    {
        $html = $this->block->render([
            'links' => [
                ['platform' => 'twitter', 'url' => '" onclick="alert(1)'],
            ],
        ]);

        self::assertStringContainsString('href="&quot; onclick=&quot;alert(1)', $html);
    }

    #[Test]
    public function validatesRequiredLinks(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('links is required and must be an array', $errors);
    }

    #[Test]
    public function validatesInvalidPlatform(): void
    {
        $errors = $this->block->validate([
            'links' => [
                ['platform' => 'myspace', 'url' => 'https://myspace.com'],
            ],
        ]);

        self::assertContains("links[0].platform 'myspace' is not a known platform", $errors);
    }

    #[Test]
    public function validatesLinkMissingPlatform(): void
    {
        $errors = $this->block->validate([
            'links' => [
                ['url' => 'https://example.com'],
            ],
        ]);

        self::assertContains('links[0].platform is required and must be a string', $errors);
    }

    #[Test]
    public function validatesLinkMissingUrl(): void
    {
        $errors = $this->block->validate([
            'links' => [
                ['platform' => 'twitter'],
            ],
        ]);

        self::assertContains('links[0].url is required and must be a string', $errors);
    }

    #[Test]
    public function validatesInvalidStyle(): void
    {
        $errors = $this->block->validate([
            'links' => [
                ['platform' => 'twitter', 'url' => 'https://twitter.com'],
            ],
            'style' => 'fancy',
        ]);

        self::assertContains('style must be one of: icons, text, both', $errors);
    }

    #[Test]
    public function validatesInvalidSize(): void
    {
        $errors = $this->block->validate([
            'links' => [
                ['platform' => 'twitter', 'url' => 'https://twitter.com'],
            ],
            'size' => 'xl',
        ]);

        self::assertContains('size must be one of: sm, md, lg', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'links' => [
                ['platform' => 'twitter', 'url' => 'https://twitter.com/test'],
                ['platform' => 'bluesky', 'url' => 'https://bsky.app/test'],
            ],
            'style' => 'icons',
            'size' => 'sm',
        ]);

        self::assertSame([], $errors);
    }
}
