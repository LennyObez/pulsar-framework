<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\CounterBlock;

#[CoversClass(CounterBlock::class)]
final class CounterBlockTest extends TestCase
{
    private CounterBlock $block;

    protected function setUp(): void
    {
        $this->block = new CounterBlock();
    }

    #[Test]
    public function typeReturnsCounter(): void
    {
        self::assertSame('counter', $this->block->type());
    }

    #[Test]
    public function rendersWithRequiredFieldsOnly(): void
    {
        $html = $this->block->render([
            'items' => [
                ['value' => '100', 'label' => 'Users'],
                ['value' => '50', 'label' => 'Projects'],
            ],
        ]);

        self::assertStringContainsString('class="counters"', $html);
        self::assertStringContainsString('grid-template-columns:repeat(2,1fr)', $html);
        self::assertStringContainsString('<span class="counter__value">100</span>', $html);
        self::assertStringContainsString('<span class="counter__label">Users</span>', $html);
        self::assertStringContainsString('<span class="counter__value">50</span>', $html);
        self::assertStringContainsString('<span class="counter__label">Projects</span>', $html);
    }

    #[Test]
    public function rendersWithCustomColumns(): void
    {
        $html = $this->block->render([
            'items' => [
                ['value' => '10', 'label' => 'A'],
            ],
            'columns' => 4,
        ]);

        self::assertStringContainsString('grid-template-columns:repeat(4,1fr)', $html);
    }

    #[Test]
    public function escapesXssInValues(): void
    {
        $html = $this->block->render([
            'items' => [
                ['value' => '<script>xss</script>', 'label' => 'Test'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function escapesXssInLabels(): void
    {
        $html = $this->block->render([
            'items' => [
                ['value' => '10', 'label' => '<img onerror="xss">'],
            ],
        ]);

        self::assertStringNotContainsString('<img onerror', $html);
    }

    #[Test]
    public function validatesRequiredItems(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('items is required and must be an array', $errors);
    }

    #[Test]
    public function validatesItemValueRequired(): void
    {
        $errors = $this->block->validate([
            'items' => [['label' => 'Missing value']],
        ]);

        self::assertContains('items[0].value is required and must be a string', $errors);
    }

    #[Test]
    public function validatesItemLabelRequired(): void
    {
        $errors = $this->block->validate([
            'items' => [['value' => '10']],
        ]);

        self::assertContains('items[0].label is required and must be a string', $errors);
    }

    #[Test]
    public function validatesColumnsPositiveInteger(): void
    {
        $errors = $this->block->validate([
            'items' => [['value' => '10', 'label' => 'A']],
            'columns' => 0,
        ]);

        self::assertContains('columns must be a positive integer', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'items' => [
                ['value' => '100', 'label' => 'Users'],
            ],
            'columns' => 3,
        ]);

        self::assertSame([], $errors);
    }
}
