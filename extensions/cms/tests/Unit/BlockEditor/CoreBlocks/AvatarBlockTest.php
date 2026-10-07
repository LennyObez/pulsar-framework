<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\AvatarBlock;

#[CoversClass(AvatarBlock::class)]
final class AvatarBlockTest extends TestCase
{
    private AvatarBlock $block;

    protected function setUp(): void
    {
        $this->block = new AvatarBlock();
    }

    public function testType(): void
    {
        self::assertSame('avatar', $this->block->type());
    }

    public function testRenderWithImage(): void
    {
        $html = $this->block->render([
            'src' => '/avatar.jpg',
            'alt' => 'Jane Doe',
            'size' => 'lg',
            'shape' => 'circle',
        ]);

        self::assertStringContainsString('src="/avatar.jpg"', $html);
        self::assertStringContainsString('alt="Jane Doe"', $html);
        self::assertStringContainsString('avatar-block--lg', $html);
        self::assertStringContainsString('avatar-block--circle', $html);
    }

    public function testRenderFallbackToInitials(): void
    {
        $html = $this->block->render([
            'alt' => 'Jane Doe',
            'name' => 'Jane Doe',
        ]);

        self::assertStringContainsString('avatar-block__initials', $html);
        self::assertStringContainsString('JD', $html);
    }

    public function testRenderSingleNameInitial(): void
    {
        $html = $this->block->render([
            'alt' => 'Admin',
            'name' => 'Admin',
        ]);

        self::assertStringContainsString('>A</span>', $html);
    }

    public function testRenderEmptyNameShowsQuestionMark(): void
    {
        $html = $this->block->render([
            'alt' => 'Unknown',
            'name' => '',
        ]);

        self::assertStringContainsString('>?</span>', $html);
    }

    public function testRenderWithCustomWidth(): void
    {
        $html = $this->block->render([
            'src' => '/img.jpg',
            'alt' => 'User',
            'width' => 64,
        ]);

        self::assertStringContainsString('width="64"', $html);
        self::assertStringContainsString('height="64"', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'src' => '"><script>xss</script>',
            'alt' => '"><b>bold</b>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testValidateRequiresAlt(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('alt is required and must be a string', $errors);
    }

    public function testValidateRejectsInvalidSize(): void
    {
        $errors = $this->block->validate([
            'alt' => 'User',
            'size' => 'huge',
        ]);

        self::assertNotEmpty($errors);
    }

    public function testValidateRejectsInvalidWidth(): void
    {
        $errors = $this->block->validate([
            'alt' => 'User',
            'width' => 5,
        ]);

        self::assertNotEmpty($errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'alt' => 'Avatar',
            'src' => '/img.jpg',
            'size' => 'md',
            'shape' => 'rounded',
        ]);

        self::assertSame([], $errors);
    }
}
