<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ListBlock;

#[CoversClass(ListBlock::class)]
final class ListBlockTest extends TestCase
{
    private ListBlock $block;

    protected function setUp(): void
    {
        $this->block = new ListBlock();
    }

    #[Test]
    public function typeReturnsList(): void
    {
        self::assertSame('list', $this->block->type());
    }

    #[Test]
    public function renderOutputsUnorderedList(): void
    {
        $html = $this->block->render([
            'items' => ['Apple', 'Banana'],
            'ordered' => false,
        ]);

        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<li>Apple</li>', $html);
        self::assertStringContainsString('<li>Banana</li>', $html);
        self::assertStringContainsString('</ul>', $html);
    }

    #[Test]
    public function renderOutputsOrderedList(): void
    {
        $html = $this->block->render([
            'items' => ['First', 'Second'],
            'ordered' => true,
        ]);

        self::assertStringContainsString('<ol>', $html);
        self::assertStringContainsString('</ol>', $html);
    }

    #[Test]
    public function renderEscapesHtmlInItems(): void
    {
        $html = $this->block->render([
            'items' => ['<b>Bold</b> & "quotes"'],
            'ordered' => false,
        ]);

        self::assertStringContainsString('&lt;b&gt;', $html);
        self::assertStringContainsString('&amp;', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingItems(): void
    {
        $errors = $this->block->validate(['ordered' => true]);

        self::assertStringContainsString('items is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForNonStringItems(): void
    {
        $errors = $this->block->validate([
            'items' => [42, true],
            'ordered' => false,
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsErrorForMissingOrdered(): void
    {
        $errors = $this->block->validate([
            'items' => ['A'],
        ]);

        self::assertStringContainsString('ordered is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'items' => ['A', 'B'],
            'ordered' => true,
        ]));
    }
}
