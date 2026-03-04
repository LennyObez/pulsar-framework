<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\NavigationBlock;

#[CoversClass(NavigationBlock::class)]
final class NavigationBlockTest extends TestCase
{
    private NavigationBlock $block;

    protected function setUp(): void
    {
        $this->block = new NavigationBlock();
    }

    public function testType(): void
    {
        self::assertSame('navigation', $this->block->type());
    }

    public function testRenderWithItems(): void
    {
        $html = $this->block->render([
            'items' => [
                ['label' => 'Home', 'url' => '/', 'active' => true],
                ['label' => 'About', 'url' => '/about'],
            ],
        ]);

        self::assertStringContainsString('<nav', $html);
        self::assertStringContainsString('aria-label="Navigation"', $html);
        self::assertStringContainsString('>Home</a>', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('>About</a>', $html);
    }

    public function testRenderWithSubmenu(): void
    {
        $html = $this->block->render([
            'items' => [
                [
                    'label' => 'Docs',
                    'url' => '/docs',
                    'children' => [
                        ['label' => 'Getting Started', 'url' => '/docs/start'],
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('navigation-block__submenu', $html);
        self::assertStringContainsString('Getting Started</a>', $html);
    }

    public function testRenderVerticalOrientation(): void
    {
        $html = $this->block->render([
            'items' => [['label' => 'Home', 'url' => '/']],
            'orientation' => 'vertical',
        ]);

        self::assertStringContainsString('navigation-block--vertical', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'items' => [
                ['label' => '<script>xss</script>', 'url' => '"><img src=x>'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testValidateRequiresItems(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('items is required and must be an array', $errors);
    }

    public function testValidateRejectsInvalidOrientation(): void
    {
        $errors = $this->block->validate([
            'items' => [['label' => 'Home', 'url' => '/']],
            'orientation' => 'diagonal',
        ]);

        self::assertNotEmpty($errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'items' => [
                ['label' => 'Home', 'url' => '/'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
