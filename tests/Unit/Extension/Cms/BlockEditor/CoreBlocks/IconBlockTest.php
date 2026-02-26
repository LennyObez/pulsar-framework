<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\IconBlock;

#[CoversClass(IconBlock::class)]
final class IconBlockTest extends TestCase
{
    private IconBlock $block;

    protected function setUp(): void
    {
        $this->block = new IconBlock();
    }

    #[Test]
    public function typeReturnsIcon(): void
    {
        self::assertSame('icon', $this->block->type());
    }

    #[Test]
    public function rendersIconWithClassNames(): void
    {
        $html = $this->block->render(['name' => 'star', 'size' => 'lg']);

        self::assertStringContainsString('class="icon icon-star icon--lg"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('<span', $html);
    }

    #[Test]
    public function rendersIconWithDefaultSize(): void
    {
        $html = $this->block->render(['name' => 'heart']);

        self::assertStringContainsString('icon--md', $html);
    }

    #[Test]
    public function rendersIconWithColorStyle(): void
    {
        $html = $this->block->render([
            'name' => 'warning',
            'color' => '#ff0000',
        ]);

        self::assertStringContainsString('style="color:#ff0000"', $html);
    }

    #[Test]
    public function omitsStyleWhenNoColorProvided(): void
    {
        $html = $this->block->render(['name' => 'star']);

        self::assertStringNotContainsString('style=', $html);
    }

    #[Test]
    public function invalidSizeDefaultsToMd(): void
    {
        $html = $this->block->render(['name' => 'star', 'size' => 'huge']);

        self::assertStringContainsString('icon--md', $html);
        self::assertStringNotContainsString('icon--huge', $html);
    }

    #[Test]
    public function escapesXssInName(): void
    {
        $html = $this->block->render([
            'name' => '<script>xss</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function escapesXssInColor(): void
    {
        $html = $this->block->render([
            'name' => 'star',
            'color' => '" onclick="alert(1)',
        ]);

        self::assertStringContainsString('color:&quot;', $html);
    }

    #[Test]
    public function validatesRequiredName(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('name is required and must be a string', $errors);
    }

    #[Test]
    public function validatesInvalidSize(): void
    {
        $errors = $this->block->validate([
            'name' => 'star',
            'size' => 'huge',
        ]);

        self::assertContains('size must be one of: sm, md, lg, xl', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'name' => 'star',
            'size' => 'lg',
        ]);

        self::assertSame([], $errors);
    }
}
