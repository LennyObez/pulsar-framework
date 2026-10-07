<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

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
    public function renderOutputsCounterItems(): void
    {
        $html = $this->block->render([
            'items' => [
                ['value' => '1000+', 'label' => 'Users'],
                ['value' => '99%', 'label' => 'Uptime'],
            ],
        ]);

        self::assertStringContainsString('counter__value', $html);
        self::assertStringContainsString('1000+', $html);
        self::assertStringContainsString('Uptime', $html);
    }

    #[Test]
    public function renderUsesItemCountAsDefaultColumns(): void
    {
        $html = $this->block->render([
            'items' => [
                ['value' => 'A', 'label' => 'X'],
                ['value' => 'B', 'label' => 'Y'],
                ['value' => 'C', 'label' => 'Z'],
            ],
        ]);

        self::assertStringContainsString('repeat(3,1fr)', $html);
    }

    #[Test]
    public function renderUsesExplicitColumnCount(): void
    {
        $html = $this->block->render([
            'items' => [['value' => 'A', 'label' => 'X']],
            'columns' => 4,
        ]);

        self::assertStringContainsString('repeat(4,1fr)', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingItems(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('items is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForInvalidItemFields(): void
    {
        $errors = $this->block->validate([
            'items' => [['value' => 42, 'label' => null]],
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsErrorForNegativeColumns(): void
    {
        $errors = $this->block->validate([
            'items' => [['value' => 'X', 'label' => 'Y']],
            'columns' => -1,
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'items' => [['value' => '42', 'label' => 'Answer']],
        ]));
    }
}
