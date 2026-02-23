<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

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
    public function rendersUnorderedList(): void
    {
        $html = $this->block->render([
            'items' => ['Alpha', 'Beta'],
            'ordered' => false,
        ]);

        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<li>Alpha</li>', $html);
        self::assertStringContainsString('<li>Beta</li>', $html);
        self::assertStringContainsString('</ul>', $html);
    }

    #[Test]
    public function rendersOrderedList(): void
    {
        $html = $this->block->render([
            'items' => ['First', 'Second'],
            'ordered' => true,
        ]);

        self::assertStringContainsString('<ol>', $html);
        self::assertStringContainsString('</ol>', $html);
    }

    #[Test]
    public function escapesXssInItems(): void
    {
        $html = $this->block->render([
            'items' => ['<script>alert(1)</script>'],
            'ordered' => false,
        ]);

        self::assertStringNotContainsString('<script>', $html);
    }

    #[Test]
    public function validatesRequiredItems(): void
    {
        $errors = $this->block->validate(['ordered' => false]);

        self::assertContains('items is required and must be an array', $errors);
    }

    #[Test]
    public function validatesRequiredOrdered(): void
    {
        $errors = $this->block->validate(['items' => ['a']]);

        self::assertContains('ordered is required and must be a boolean', $errors);
    }

    #[Test]
    public function validatesItemsMustBeStrings(): void
    {
        $errors = $this->block->validate(['items' => [42], 'ordered' => false]);

        self::assertContains('items[0] must be a string', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['items' => ['a', 'b'], 'ordered' => true]);

        self::assertSame([], $errors);
    }
}
