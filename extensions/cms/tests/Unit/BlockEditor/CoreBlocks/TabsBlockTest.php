<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\TabsBlock;

#[CoversClass(TabsBlock::class)]
final class TabsBlockTest extends TestCase
{
    private TabsBlock $block;

    protected function setUp(): void
    {
        $this->block = new TabsBlock();
    }

    #[Test]
    public function typeReturnsTabs(): void
    {
        self::assertSame('tabs', $this->block->type());
    }

    #[Test]
    public function renderOutputsTablistWithPanels(): void
    {
        $html = $this->block->render([
            'tabs' => [
                ['title' => 'Tab 1', 'content' => 'Content 1'],
                ['title' => 'Tab 2', 'content' => 'Content 2'],
            ],
        ]);

        self::assertStringContainsString('role="tablist"', $html);
        self::assertStringContainsString('role="tab"', $html);
        self::assertStringContainsString('role="tabpanel"', $html);
        self::assertStringContainsString('Tab 1</button>', $html);
        self::assertStringContainsString('Content 2</div>', $html);
    }

    #[Test]
    public function renderFirstTabIsActiveByDefault(): void
    {
        $html = $this->block->render([
            'tabs' => [
                ['title' => 'First', 'content' => 'C1'],
                ['title' => 'Second', 'content' => 'C2'],
            ],
        ]);

        self::assertStringContainsString('aria-selected="true"', $html);
        // Second tab is not selected
        self::assertStringContainsString('aria-selected="false"', $html);
    }

    #[Test]
    public function renderRespectsDefaultActiveIndex(): void
    {
        $html = $this->block->render([
            'tabs' => [
                ['title' => 'A', 'content' => 'CA'],
                ['title' => 'B', 'content' => 'CB'],
            ],
            'defaultActive' => 1,
        ]);

        // Second panel should not be hidden
        self::assertStringContainsString('id="panel-1"', $html);
    }

    #[Test]
    public function renderSupportsAnchorAndClassName(): void
    {
        $html = $this->block->render([
            'tabs' => [['title' => 'T', 'content' => 'C']],
            'anchor' => 'my-tabs',
            'className' => 'styled',
        ]);

        self::assertStringContainsString('id="my-tabs"', $html);
        self::assertStringContainsString('styled', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingTabs(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('tabs is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingTabFields(): void
    {
        $errors = $this->block->validate([
            'tabs' => [['title' => 123]],
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsErrorForNegativeDefaultActive(): void
    {
        $errors = $this->block->validate([
            'tabs' => [['title' => 'T', 'content' => 'C']],
            'defaultActive' => -1,
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsErrorForDefaultActiveExceedingCount(): void
    {
        $errors = $this->block->validate([
            'tabs' => [['title' => 'T', 'content' => 'C']],
            'defaultActive' => 5,
        ]);

        self::assertStringContainsString('less than the number of tabs', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'tabs' => [
                ['title' => 'Tab', 'content' => 'Content'],
            ],
        ]));
    }
}
