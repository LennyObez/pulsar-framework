<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\SiteTitleBlock;

#[CoversClass(SiteTitleBlock::class)]
final class SiteTitleBlockTest extends TestCase
{
    private SiteTitleBlock $block;

    protected function setUp(): void
    {
        $this->block = new SiteTitleBlock();
    }

    public function testType(): void
    {
        self::assertSame('site-title', $this->block->type());
    }

    public function testRenderWithDefaults(): void
    {
        $html = $this->block->render(['title' => 'My Site']);

        self::assertStringContainsString('<h1', $html);
        self::assertStringContainsString('My Site</a>', $html);
        self::assertStringContainsString('href="/"', $html);
    }

    public function testRenderWithTagline(): void
    {
        $html = $this->block->render([
            'title' => 'Pulsar',
            'tagline' => 'The framework for regulated apps',
        ]);

        self::assertStringContainsString('Pulsar', $html);
        self::assertStringContainsString('The framework for regulated apps', $html);
        self::assertStringContainsString('site-title-block__tagline', $html);
    }

    public function testRenderWithCustomTag(): void
    {
        $html = $this->block->render([
            'title' => 'Test',
            'tag' => 'p',
        ]);

        self::assertStringContainsString('<p class="site-title-block__title">', $html);
    }

    public function testRenderWithoutLink(): void
    {
        $html = $this->block->render([
            'title' => 'Static',
            'linkToHome' => false,
        ]);

        self::assertStringNotContainsString('<a', $html);
        self::assertStringContainsString('Static', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'title' => '<script>alert(1)</script>',
            'tagline' => '<img src=x>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testValidateRequiresTitle(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('title is required and must be a string', $errors);
    }

    public function testValidateRejectsInvalidTag(): void
    {
        $errors = $this->block->validate([
            'title' => 'Test',
            'tag' => 'div',
        ]);

        self::assertNotEmpty($errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'title' => 'My Site',
            'tag' => 'h2',
        ]);

        self::assertSame([], $errors);
    }
}
