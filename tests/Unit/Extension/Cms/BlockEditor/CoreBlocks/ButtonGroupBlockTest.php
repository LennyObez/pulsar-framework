<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ButtonGroupBlock;

#[CoversClass(ButtonGroupBlock::class)]
final class ButtonGroupBlockTest extends TestCase
{
    private ButtonGroupBlock $block;

    protected function setUp(): void
    {
        $this->block = new ButtonGroupBlock();
    }

    #[Test]
    public function typeReturnsButtonGroup(): void
    {
        self::assertSame('button-group', $this->block->type());
    }

    #[Test]
    public function rendersButtons(): void
    {
        $html = $this->block->render([
            'buttons' => [
                ['text' => 'Buy Now', 'url' => '/shop'],
                ['text' => 'Learn More', 'url' => '/about'],
            ],
        ]);

        self::assertStringContainsString('class="button-group button-group--center button-group--horizontal"', $html);
        self::assertStringContainsString('<a href="/shop" class="button-group__button">Buy Now</a>', $html);
        self::assertStringContainsString('<a href="/about" class="button-group__button">Learn More</a>', $html);
    }

    #[Test]
    public function rendersWithAlignmentAndLayout(): void
    {
        $html = $this->block->render([
            'buttons' => [['text' => 'Click', 'url' => '/go']],
            'alignment' => 'right',
            'layout' => 'vertical',
        ]);

        self::assertStringContainsString('button-group--right', $html);
        self::assertStringContainsString('button-group--vertical', $html);
    }

    #[Test]
    public function defaultsInvalidAlignmentToCenter(): void
    {
        $html = $this->block->render([
            'buttons' => [['text' => 'Click', 'url' => '/go']],
            'alignment' => 'stretch',
        ]);

        self::assertStringContainsString('button-group--center', $html);
    }

    #[Test]
    public function defaultsInvalidLayoutToHorizontal(): void
    {
        $html = $this->block->render([
            'buttons' => [['text' => 'Click', 'url' => '/go']],
            'layout' => 'grid',
        ]);

        self::assertStringContainsString('button-group--horizontal', $html);
    }

    #[Test]
    public function escapesXssInText(): void
    {
        $html = $this->block->render([
            'buttons' => [
                ['text' => '<script>xss</script>', 'url' => '/safe'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function escapesXssInUrl(): void
    {
        $html = $this->block->render([
            'buttons' => [
                ['text' => 'Click', 'url' => '" onclick="alert(1)'],
            ],
        ]);

        self::assertStringContainsString('href="&quot;', $html);
    }

    #[Test]
    public function validatesRequiredButtons(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('buttons is required and must be an array', $errors);
    }

    #[Test]
    public function validatesButtonsMustBeArray(): void
    {
        $errors = $this->block->validate(['buttons' => 'not-array']);

        self::assertContains('buttons is required and must be an array', $errors);
    }

    #[Test]
    public function validatesButtonItemsMustBeObjects(): void
    {
        $errors = $this->block->validate(['buttons' => ['not-an-object']]);

        self::assertContains('buttons[0] must be an object', $errors);
    }

    #[Test]
    public function validatesButtonTextRequired(): void
    {
        $errors = $this->block->validate([
            'buttons' => [['url' => '/shop']],
        ]);

        self::assertContains('buttons[0].text is required and must be a string', $errors);
    }

    #[Test]
    public function validatesButtonUrlRequired(): void
    {
        $errors = $this->block->validate([
            'buttons' => [['text' => 'Buy']],
        ]);

        self::assertContains('buttons[0].url is required and must be a string', $errors);
    }

    #[Test]
    public function validatesInvalidAlignment(): void
    {
        $errors = $this->block->validate([
            'buttons' => [['text' => 'Buy', 'url' => '/shop']],
            'alignment' => 'stretch',
        ]);

        self::assertContains('alignment must be one of: left, center, right', $errors);
    }

    #[Test]
    public function validatesAlignmentMustBeString(): void
    {
        $errors = $this->block->validate([
            'buttons' => [['text' => 'Buy', 'url' => '/shop']],
            'alignment' => 123,
        ]);

        self::assertContains('alignment must be a string', $errors);
    }

    #[Test]
    public function validatesInvalidLayout(): void
    {
        $errors = $this->block->validate([
            'buttons' => [['text' => 'Buy', 'url' => '/shop']],
            'layout' => 'grid',
        ]);

        self::assertContains('layout must be one of: horizontal, vertical', $errors);
    }

    #[Test]
    public function validatesLayoutMustBeString(): void
    {
        $errors = $this->block->validate([
            'buttons' => [['text' => 'Buy', 'url' => '/shop']],
            'layout' => 42,
        ]);

        self::assertContains('layout must be a string', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'buttons' => [['text' => 'Buy', 'url' => '/shop']],
            'alignment' => 'left',
            'layout' => 'vertical',
        ]);

        self::assertSame([], $errors);
    }
}
